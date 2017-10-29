<?php
namespace CSVImport\Controller;

use CSVImport\Form\ImportForm;
use CSVImport\Form\MappingForm;
use CSVImport\Source\SourceInterface;
use CSVImport\Job\Import;
use finfo;
use Omeka\Media\Ingester\Manager;
use Omeka\Service\Exception\ConfigException;
use Omeka\Settings\UserSettings;
use Omeka\Stdlib\Message;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class IndexController extends AbstractActionController
{
    /**
     * @var string
     */
    protected $tempPath;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Manager
     */
    protected $mediaIngesterManager;

    /**
     * @var UserSettings
     */
    protected $userSettings;

    /**
     * @param array $config
     * @param Manager $mediaIngesterManager
     * @param UserSettings $userSettings
     */
    public function __construct(array $config, Manager $mediaIngesterManager, UserSettings $userSettings)
    {
        $this->config = $config;
        $this->mediaIngesterManager = $mediaIngesterManager;
        $this->userSettings = $userSettings;
    }

    public function indexAction()
    {
        $view = new ViewModel;
        $form = $this->getForm(ImportForm::class);
        $view->form = $form;
        return $view;
    }

    public function mapAction()
    {
        $view = new ViewModel;
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $files = $request->getFiles()->toArray();
        $post = $this->params()->fromPost();
        $mappingOptions = array_intersect_key($post, array_flip([
            'resource_type',
            'delimiter',
            'enclosure',
            'automap_check_names_alone',
            'automap_check_user_list',
            'automap_user_list',
        ]));
        $form = $this->getForm(MappingForm::class, $mappingOptions);
        if (empty($files)) {
            $form->setData($post);
            if ($form->isValid()) {
                $args = $this->cleanArgs($post);
                $this->saveUserSettings($args);
                unset($args['multivalue_by_default']);
                if (empty($args['automap_check_user_list'])) {
                    unset($args['automap_user_list']);
                }
                $dispatcher = $this->jobDispatcher();
                $job = $dispatcher->dispatch('CSVImport\Job\Import', $args);
                // The CsvImport record is created in the job, so it doesn't
                // happen until the job is done.
                $message = new Message(
                    'Importing in background (%sjob #%d%s)', // @translate
                    sprintf('<a href="%s">',
                        htmlspecialchars($this->url()->fromRoute('admin/id', ['controller' => 'job', 'id' => $job->getId()]))
                    ),
                    $job->getId(),
                   '</a>'
                );
                $message->setEscapeHtml(false);
                $this->messenger()->addSuccess($message);
                return $this->redirect()->toRoute('admin/csvimport/past-imports', ['action' => 'browse'], true);
            }

            // TODO Keep user variables when the form is invalid.
            $this->messenger()->addError('Invalid settings.'); // @translate
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $importForm = $this->getForm(ImportForm::class);
        $post = array_merge_recursive(
            $request->getPost()->toArray(),
            $request->getFiles()->toArray()
        );
        $importForm->setData($post);
        if (!$importForm->isValid()) {
            $this->messenger()->addFormErrors($importForm);
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $source = $this->getSource($post['source']);
        if (empty($source)) {
            $this->messenger()->addError('The format of the source cannot be detected.'); // @translate
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $resourceType = $post['resource_type'];
        $mediaType = $source->getMediaType();
        $post['media_type'] = $mediaType;
        $tempPath = $this->getTempPath();
        $this->moveToTemp($post['source']['tmp_name']);
        $args = $this->cleanArgs($post);

        $source->init($this->config);
        $source->setSource($tempPath);
        $source->setParameters($args);

        if (!$source->isValid()) {
            $message = $source->getErrorMessage() ?: 'The file is not valid.'; // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $columns = $source->getHeaders();
        if (empty($columns)) {
            $message = $source->getErrorMessage() ?: 'The file has no headers.'; // @translate
            $this->messenger()->addError($message);
            return $this->redirect()->toRoute('admin/csvimport');
        }

        $automapCheckNamesAlone = (bool) $post['automap_check_names_alone'];
        $automapCheckUserList = (bool) $post['automap_check_user_list'];
        $automapUserList = $this->getForm(ImportForm::class)
            ->convertUserListTextToArray($post['automap_user_list']);
        $automapOptions = [];
        $automapOptions['check_names_alone'] = $automapCheckNamesAlone;
        $automapOptions['format'] = 'form';
        if ($automapCheckUserList) {
            $automapOptions['automap_list'] = $automapUserList;
        }
        $autoMaps = $this->automapHeadersToMetadata($columns, $resourceType, $automapOptions);

        $view->setVariable('form', $form);
        $view->setVariable('resourceType', $resourceType);
        $view->setVariable('filepath', $tempPath);
        $view->setVariable('filename', $post['source']['name']);
        $view->setVariable('filesize', $post['source']['size']);
        $view->setVariable('mediaType', $mediaType);
        $view->setVariable('columns', $columns);
        $view->setVariable('automaps', $autoMaps);
        $view->setVariable('mappings', $this->getMappingsForResource($resourceType));
        $view->setVariable('mediaForms', $this->getMediaForms());
        return $view;
    }

    public function pastImportsAction()
    {
        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $undoJobIds = [];
            foreach ($data['jobs'] as $jobId) {
                $undoJob = $this->undoJob($jobId);
                $undoJobIds[] = $undoJob->getId();
            }
            $message = new Message(
                'Undo in progress in the following jobs: %s', // @translate
                implode(', ', $undoJobIds));
            $this->messenger()->addSuccess($message);
        }
        $view = new ViewModel;
        $page = $this->params()->fromQuery('page', 1);
        $query = $this->params()->fromQuery() + [
            'page' => $page,
            'sort_by' => $this->params()->fromQuery('sort_by', 'id'),
            'sort_order' => $this->params()->fromQuery('sort_order', 'desc'),
        ];
        $response = $this->api()->search('csvimport_imports', $query);
        $this->paginator($response->getTotalResults(), $page);
        $view->setVariable('imports', $response->getContent());
        return $view;
    }

    /**
     * Get the source class to manage the file, according to its media type.
     *
     * @todo Use the class TempFile before.
     *
     * @param array $fileData File data from a post ($_FILES).
     * @return SourceInterface|null
     */
    protected function getSource(array $fileData)
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mediaType = $finfo->file($fileData['tmp_name']);

        // Manage an exception for a very common format, undetected by fileinfo.
        if ($mediaType === 'text/plain') {
            $extensions = [
                'csv' => 'text/csv',
                'tab' => 'text/tab-separated-values',
                'tsv' => 'text/tab-separated-values',
            ];
            $extension = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
            if (isset($extensions[$extension])) {
                $mediaType = $extensions[$extension];
            }
        }

        $sources = $this->config['csv_import']['sources'];
        if (!isset($sources[$mediaType])) {
            return;
        }

        $source = new $sources[$mediaType];
        return $source;
    }

    /**
     * Helper to return ordered mappings of the selected resource type.
     *
     * @param string $resourceType
     * @return array
     */
    protected function getMappingsForResource($resourceType)
    {
        // First reorder mappings: for ergonomic reasons, it’s cleaner to keep
        // the buttons of modules after the default ones. This is only needed in
        // the mapping form. The default order is set in this module config too,
        // before Zend merge.
        $config = include __DIR__ . '/../../config/module.config.php';
        $defaultOrder = $config['csv_import']['mappings'];
        $mappings = $this->config['csv_import']['mappings'];
        if (isset($defaultOrder[$resourceType])) {
            $mappingClasses = array_values(array_unique(array_merge(
                $defaultOrder[$resourceType], $mappings[$resourceType]
            )));
        } else {
            $mappingClasses = $mappings[$resourceType];
        }
        $mappings = [];
        foreach ($mappingClasses as $mappingClass) {
            $mappings[] = new $mappingClass();
        }
        return $mappings;
    }

    /**
     * Helper to clean posted args to get more readable logs.
     *
     * @todo Mix with check in Import and make it available for external query.
     *
     * @param array $post
     * @return array
     */
    protected function cleanArgs(array $post)
    {
        $args = $post;

        // Set values as integer.
        foreach (['o:resource_template', 'o:resource_class', 'o:owner', 'o:item'] as $meta) {
            if (!empty($args[$meta]['o:id'])) {
                $args[$meta] = ['o:id' => (int) $args[$meta]['o:id']];
            }
        }
        foreach (['o:is_public', 'o:is_open', 'o:is_active'] as $meta) {
            if (isset($args[$meta]) && strlen($args[$meta])) {
                $args[$meta] = (int) $args[$meta];
            }
        }

        // Name of properties must be known to merge data and to process update.
        $api = $this->api();
        if (array_key_exists('column-property', $args)) {
            foreach ($args['column-property'] as $column => $ids) {
                $properties = [];
                foreach ($ids as $id) {
                    $term = $api->read('properties', $id)->getContent()->term();
                    $properties[$term] = (int) $id;
                }
                $args['column-property'][$column] = $properties;
            }
        }

        // Check the identifier property.
        if (array_key_exists('identifier_property', $args)) {
            $identifierProperty = $args['identifier_property'];
            if (empty($identifierProperty) && $identifierProperty !== 'internal_id') {
                $properties = $api->search('properties', ['term' => $identifierProperty])->getContent();
                if (empty($properties)) {
                    $args['identifier_property'] = null;
                }
            }
        }

        if (!array_key_exists('column-multivalue', $post)) {
            $args['column-multivalue'] = [];
        }

        // TODO Move to the source class.
        unset($args['delimiter']);
        unset($args['enclosure']);
        switch ($post['media_type']) {
            case 'text/csv':
                $args['delimiter'] = $this->getForm(ImportForm::class)->extractParameter($post['delimiter']);
                $args['enclosure'] = $this->getForm(ImportForm::class)->extractParameter($post['enclosure']);
                $args['escape'] = \CSVImport\Source\CsvFile::DEFAULT_ESCAPE;
                break;
            case 'text/tab-separated-values':
                // Nothing to do.
                break;
        }

        if (array_key_exists('multivalue_separator', $post)) {
            unset($args['multivalue_separator']);
            $args['multivalue_separator'] = $post['multivalue_separator'];
        }

        if (!empty($post['rows_by_batch'])) {
            $args['rows_by_batch'] = (int) $post['rows_by_batch'];
        }

        // Convert the user text into an array.
        if (array_key_exists('automap_user_list', $args)) {
            $args['automap_user_list'] = $this->getForm(ImportForm::class)
                ->convertUserListTextToArray($args['automap_user_list']);
        }

        // Set a default owner for a creation.
        if (empty($args['o:owner']['o:id']) && (empty($args['action']) || $args['action'] === Import::ACTION_CREATE)) {
            $args['o:owner'] = ['o:id' => $this->identity()->getId()];
        }

        // Remove useless input fields from sidebars.
        unset($args['value-language']);
        unset($args['column-resource_property']);
        unset($args['column-item_set_property']);
        unset($args['column-item_property']);
        unset($args['column-media_property']);

        return $args;
    }

    /**
     * Save user settings.
     *
     * @param array $settings
     */
    protected function saveUserSettings(array $settings)
    {
        foreach ($this->config['csv_import']['user_settings'] as $key => $value) {
            $name = substr($key, strlen('csv_import_'));
            if (isset($settings[$name])) {
                $this->userSettings()->set($key, $settings[$name]);
            }
        }
    }

    protected function getMediaForms()
    {
        $mediaIngester = $this->mediaIngesterManager;

        $forms = [];
        foreach ($mediaIngester->getRegisteredNames() as $ingester) {
            $forms[$ingester] = [
                'label' => $mediaIngester->get($ingester)->getLabel(),
            ];
        }
        ksort($forms);
        return $forms;
    }

    /**
     * Move a file to the temp path.
     *
     * @param string $systemTempPath
     */
    protected function moveToTemp($systemTempPath)
    {
        move_uploaded_file($systemTempPath, $this->getTempPath());
    }

    /**
     * Get the path to the temporary file.
     *
     * @param null|string $tempDir
     * @return string
     */
    protected function getTempPath($tempDir = null)
    {
        if (isset($this->tempPath)) {
            return $this->tempPath;
        }
        if (!isset($tempDir)) {
            $config = $this->config;
            if (!isset($config['temp_dir'])) {
                throw new ConfigException('Missing temporary directory configuration');
            }
            $tempDir = $config['temp_dir'];
        }
        $this->tempPath = tempnam($tempDir, 'omeka');
        return $this->tempPath;
    }

    protected function undoJob($jobId)
    {
        $response = $this->api()->search('csvimport_imports', ['job_id' => $jobId]);
        $csvImport = $response->getContent()[0];
        $dispatcher = $this->jobDispatcher();
        $job = $dispatcher->dispatch('CSVImport\Job\Undo', ['jobId' => $jobId]);
        $response = $this->api()->update('csvimport_imports',
            $csvImport->id(),
            [
                'o:undo_job' => ['o:id' => $job->getId() ],
            ]
        );
        return $job;
    }
}
