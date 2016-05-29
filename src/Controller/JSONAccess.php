<?php
namespace Bolt\Extension\SerWeb\Rest\Controller;

/**
 * JSONAccess library for content.
 *
 * @author Tobias Dammers <tobias@twokings.nl>
 * @author Luciano Rodriguez <info@serweb.com.ar>
 */


class JSONAccess
{
    protected $app;
    public function __construct($app){
        $this->app = $app;
    }

    public function json_list($contenttype, $items)
    {

        // If we don't have any items, this can mean one of two things: either
        // the content type does not exist (in which case we'll get a non-array
        // response), or it exists, but no content has been added yet.
        if (!is_array($items)) {
            throw new \Exception("Configuration error: $contenttype is configured as a JSON end-point, but doesn't exist as a content type.");
        }
        if (empty($items)) {
            $items = array();
        }

        $items = array_values($items);
        $items = array_map(array($this, 'clean_list_item'), $items);

        return $items;

    }

    public function json($item)
    {
        $values = $this->clean_full_item($item);
        return $values;
    }

    private function clean_item($item, $type = 'list-fields')
    {
        $contenttype = $item->contenttype['slug'];
        if (isset($this->config['contenttypes'][$contenttype][$type])) {
            $fields = $this->config['contenttypes'][$contenttype][$type];
        }
        else {
            $fields = array_keys($item->contenttype['fields']);
        }
        // Always include the ID in the set of fields
        array_unshift($fields, 'id');
        $fields = array_unique($fields);
        $values = array();
        foreach ($fields as $key => $field) {
            $values[$field] = $item->values[$field];
        }

        // Check if we have image or file fields present. If so, see if we need to
        // use the full URL's for these.
        if (isset($values[$key]['file'])) {
        foreach($item->contenttype['fields'] as $key => $field) {
            if (($field['type'] == 'image' || $field['type'] == 'file') && isset($values[$key])) {
                $values[$key]['url'] = sprintf('%s%s%s',
                    $this->app['paths']['canonical'],
                    $this->app['paths']['files'],
                    $values[$key]['file']
                    );
            }
            if ($field['type'] == 'image' && isset($values[$key]) && is_array($this->config['thumbnail'])) {
                // dump($this->app['paths']);
                $values[$key]['thumbnail'] = sprintf('%s/thumbs/%sx%s/%s',
                    $this->app['paths']['canonical'],
                    $this->config['thumbnail']['width'],
                    $this->config['thumbnail']['height'],
                    $values[$key]['file']
                    );
            }

        } }

        return $values;

    }

    private function clean_list_item($item)
    {
        return $this->clean_item($item, 'list-fields');
    }

    private function clean_full_item($item)
    {
        return $this->clean_item($item, 'item-fields');
    }


}

