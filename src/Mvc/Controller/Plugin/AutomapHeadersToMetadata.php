<?php
namespace CSVImport\Mvc\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class AutomapHeadersToMetadata extends AbstractPlugin
{
    /**
     * Automap a list of headers to a list of metadata.
     *
     * @param array $headers
     * @param string $resourceType
     * @return array Associative array of the index of the headers as key and
     * the matching metadata as value. Only mapped headers are set.
     */
    public function __invoke(array $headers, $resourceType = null)
    {
        $automaps = [];

        $api = $this->getController()->api();

        $headers = array_map('trim', $headers);
        foreach ($headers as $index => $header) {
            switch ($resourceType) {
                case 'item_sets':
                case 'items':
                case 'media':
                    if (preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+$/i', $header)) {
                        $response = $api->search('properties', ['term' => $header]);
                        $content = $response->getContent();
                        if (!empty($content)) {
                            $property = reset($content);
                            $automaps[$index] = $property;
                            continue 2;
                        }
                    }
                    break;
                case 'users':
                    break;
            }
        }

        return $automaps;
    }
}
