<?php
namespace Bolt\Extension\SerWeb\Rest;

use Silex\Application;

/**
 * DataFormatter library for content.
 *
 * @author Luciano Rodriguez <info@serweb.com.ar>
 *
 */

class DataFormatterByQuery
{
    protected $app;
    public $fields;
    public function __construct(Application $app, $contenttype, $fields = false)
    {
        $this->app = $app;
        $this->fields = $fields;
        $this->contenttype = $contenttype;
    }

    public function simplify($obj)
    {
        $item = $obj->toArray();
        $ct = $this->app['config']->get('contenttypes/' . $this->contenttype);
        $fields = array_keys($ct['fields']);

        // Always include the ID in the set of fields
        array_unshift($fields, 'id');
        $fields = array_unique($fields);
        $values = array();

        foreach ($fields as $field) {
            // jump field excluded
            if ($this->fields && !in_array($field, $this->fields)) {
                continue;
            }

            // parse fields if its necesary
            switch ($ct['fields'][$field]['type']) {
                case 'date':
                    $values[$field] = $item[$field]->toDateTimeString();
                    break;
                default:
                    $values[$field] = $item[$field];
            }
        }

        // metadata values
        if (!$this->fields || in_array('ownerid', $this->fields)) {
            $values['ownerid'] = $item['ownerid'];
        }

        if (!$this->fields || in_array('datepublish', $this->fields)) {
            $values['datepublish'] = $item['datepublish']->toDateTimeString();
        }

        $relation = [];

        // relations
        foreach ($item['relation']->toArray() as $rel) {
            if ($rel['from_contenttype'] == $this->contenttype) {
                // from relation
                $ctrel = $rel['to_contenttype'];
                if (!array_key_exists($ctrel, $relation)) {
                    $relation[$ctrel] = array();
                }
                $relation[$ctrel][] = $rel['to_id'];
            } else {
                // to relation
                $ctrel = $rel['from_contenttype'];
                if (!array_key_exists($ctrel, $relation)) {
                    $relation[$ctrel] = array();
                }
                $relation[$ctrel][] = $rel['from_id'];
            }
        }

        $content = array(
            "values" => $values,
            "relation" => $relation
            );

        return $content;
    }


    private function cleanFullItem($item)
    {
        return $this->cleanItem($item);
    }
}
