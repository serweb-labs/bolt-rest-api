<?php
namespace Bolt\Extension\SerWeb\Rest;

/**
 * DataFormatter library for content.
 *
 * @author Luciano Rodriguez <info@serweb.com.ar>
 *
 */

use Symfony\Component\Debug\Exception\ContextErrorException;
use Carbon\Carbon;

class DataFormatter
{
    protected $app;
    private $config;
    private $params;
    private $fields;
    private $status;
    private $include;
    private $includesStack;
    private $queryCache;
    private $cache;
    public $count;

    public function __construct($app, $config, $params = array())
    {
        $this->app = $app;
        $this->config = $config;
        $this->params = $params;
        $this->fields = empty($params['fields']) ? false : $params['fields'];
        $this->status = empty($params['query']['status']) ? "published" : $params['query']['status'];
        $this->include = empty($params['include']) ? [] : $params['include'];
        $this->includesStack = [];
        $this->queryCache = [];
        $this->cache = [];
    }

    public function listing($contenttype, $items, $basic = false)
    {   
        if (!$this->isIterable($items)) {
            throw new \Exception("empty content");
        }

        if (empty($items)) {
            $items = array();
        }
        $all = [];
        foreach ($items as $item) {
            $all[] = $this->item($item);
        }

        return array(
            'links' => $basic ? array() : $this->getLinksList(),
            'meta' => $basic ? array() : array(
                 'count' => (Int) $this->count,
                 'page' => (Int) $this->params['pagination']->page,
                 'limit' => (Int) $this->params['pagination']->limit

             ),
             'data' => $all,
             'included' => $this->includesToArray($this->includesStack)
             );
    }

    public function one($item)
    {

        $content = $this->item($item);

        return array(
            'links' => $this->getLinksOne($item->id),
            'data' => $content,
            'included' => $this->includesToArray($this->includesStack)
            );
    }
    
    public function item($item, $deep = true, $child = false)
    {
        $contenttype = $item->contenttype['slug'];

        //  allowed fields
        if (isset($this->fields[$contenttype])) {
            $allowedFields = explode(",", $this->fields[$contenttype]);
        } else {
            $allowedFields = false;
        }

        $fields = array_keys($item->contenttype['fields']);
        
        // Always include the ID in the set of fields
        array_unshift($fields, 'id');

        $fields = $item->contenttype['fields'];
        $values = array();

        foreach ($fields as $field => $value) {
            if ($allowedFields && !in_array($field, $allowedFields)) {
                continue;
            }
            $values[$field] = $item->{$field};
        }

        // metadata values
        if (!$allowedFields || in_array('ownerid', $allowedFields)) {
            $values['ownerid'] = $item->ownerid;
        }
        if (!$allowedFields || in_array('datepublish', $allowedFields)) {
            $values['datepublish'] = $item->datepublish->toIso8601String();
        }
        if (!$allowedFields || in_array('datechanged', $allowedFields)) {
            $values['datechanged'] = $item->datechanged->toIso8601String();
        }
        if (!$allowedFields || in_array('datecreated', $allowedFields)) {
            $values['datecreated'] = $item->datecreated->toIso8601String();
        }

        // @TODO: custom field formatters by static class in extension config file
        // Check if we have image or file fields present. If so, see if we need to
        // use the full URL's for these.
        foreach ($item->contenttype['fields'] as $key => $field) {

            if (($field['type'] == 'image' || $field['type'] == 'file') && isset($values[$key])) {
                if (isset($values[$key]['file'])) {
                    $values[$key]['url'] = sprintf(
                        '%s%s%s',
                        $this->app['paths']['canonical'],
                        $this->app['paths']['files'],
                        $values[$key]['file']
                    );
                }
            }
            
            if ($field['type'] == 'image' && isset($values[$key]) && is_array($this->config['thumbnail'])) {
                
                if (isset($values[$key]['file'])) {                  
                    $values[$key]['thumbnail'] = sprintf(
                        '%s/thumbs/%sx%s/%s',
                        $this->app['paths']['canonical'],
                        $this->config['thumbnail']['width'],
                        $this->config['thumbnail']['height'],
                        $values[$key]['file']
                    );
                }
                
            }            
            else if ($field['type'] == 'date') {
                if (isset($values[$key]) && $values[$key] instanceof Carbon) {
                    $values[$key] =  $values[$key]->toIso8601String();
                }
            }
            
        }

        $relationship = [];

        // get explicit relations
        $relations = empty($item->contenttype['relations']) ? [] : $item->contenttype['relations'];
        $cts = array_keys($relations);

        foreach ($item->relation->toArray() as $rel) {
            if ($rel['from_contenttype'] == $contenttype) {
                // from relation
                $rct = $rel['to_contenttype'];
                $rid = $rel['to_id'];
            } else {
                // to relation
                $rct = $rel['from_contenttype'];
                $rid = $rel['from_id'];
            }

            // only return relation in contenttype.yml
            // @TODO: create and check configuration
            // like "return all relations ever"
            if (!in_array($rct, $cts)) {
                continue;
            }

            // add cache repeated
            $this->addToCache($rct, $rid);

            
            // test deleted status
            // @TODO, need RFC, too slow
            if ($this->config['delete']['soft']) {
                $f = $this->getFromCache($rct, $rid);
                if ($f) {
                    $deleted = ($f->status == $this->config['delete']['status']);
                    if ($deleted) {
                        continue;
                    }
                }
                else {
                    continue;
                }
            }

            // @TODO: RFC
            // this return deleted and not published values :/
            if (!array_key_exists($rct, $relationship)) {
                $relationship[$rct] = array(
                    "data" => array(),
                    "links" => array()
                );
            }

            $relationship[$rct]['data'][] = array('type' => $rct, 'id' => $rid);
            $relationship[$rct]['links'] = $this->getLinksRelated($item, $rct);

            // @TODO: support "dot sintax" in include
            // follow JSON API specification
            if (in_array($rct, $this->include) && $deep) {
                if (!array_key_exists($rct, $this->includesStack)) {
                    $this->includesStack[$rct] = [];
                }

                if (!array_key_exists($rid, $this->includesStack[$rct])) {
                    $this->includesStack[$rct][$rid] = $this->item($this->getFromCache($rct, $rid), false, true);
                }
            }
        }
        


        $content = array(
            "type" => $contenttype,
            "id" => $item->id,
            "attributes" => $values,
            "relationships" => $relationship,
            "links" => $this->getLinksItem($item, $child)
            );

        return $content;
    }


    private function includesToArray($includes)
    {
        $array = [];
        foreach ($includes as $ct => $items) {
            foreach ($items as $key => $value) {
                $array[] = $value;
            }
        }
        return $array;
    }

    private function getLinksItem($item)
    {
        return array(
        "self" => sprintf(
                '%s%s/%s/%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $item->contenttype['slug'],
                $item->id
            )
        );
    }

    private function getLinksList() {
        return array(
        'self' => sprintf(
                '%s%s/%s%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $this->params['contenttype']['slug'],
                $this->paramsToUri()
            ),
        'first' => sprintf(
                '%s%s/%s%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $this->params['contenttype']['slug'],
                ''
            ),
        'next' => sprintf(
                '%s%s/%s%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $this->params['contenttype']['slug'],
                $this->paramsToUri('next')
            ),
        'last' => sprintf(
                '%s%s/%s%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $this->params['contenttype']['slug'],
                $this->paramsToUri('last')
            ),
        );
    }

    private function getLinksOne($id) {
        return array(
        'self' => sprintf(
                '%s%s/%s/%s%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $this->params['contenttype']['slug'],
                $id,
                $this->paramsToUri()
            ),
        );
    }

    private function getLinksRelated($item, $rct)
    {
        return array(
        "self" => sprintf(
                '%s%s/%s/%s/relationships/%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $item->contenttype['slug'],
                $item->id,
                $rct
            ),
        "related" => sprintf(
                '%s%s/%s/%s/%s',
                $this->app['paths']['canonical'],
                $this->config['endpoints']['rest'],
                $item->contenttype['slug'],
                $item->id,
                $rct
            )        
        );
    }

    private function paramsToUri($modifier = false) {
        return "";
    }

    private function isIterable($var)
    {
        return (is_array($var) || $var instanceof \Traversable);
    }

    private function coalesce() {
        return array_shift(array_filter(func_get_args()));
    }
    private function addToCache($ct, $id)
    {   
        if (!array_key_exists($ct, $this->queryCache)) {
            $this->queryCache[$ct] = [];
        }

        if (!array_key_exists($ct, $this->cache)) {
            $this->cache[$ct] = [];
        }

        if (!array_key_exists($id, $this->queryCache[$ct])) {
            $q = "{$ct}/{$id}";
            $this->queryCache[$ct][$id] = function () use ($q) {
                return $this->app['query']->getContent($q, []);
            };
        }
    }

    private function getFromCache($ct, $id)
    {
        if (array_key_exists($ct, $this->cache)) {
            if (array_key_exists($id, $this->cache[$ct])) {
                return $this->cache[$ct][$id];
            }
        }

        if (array_key_exists($ct, $this->queryCache)) {
            if (array_key_exists($id, $this->queryCache[$ct])) {
                // fetch query
                $this->cache[$ct][$id] = $this->queryCache[$ct][$id]();
                return $this->cache[$ct][$id];
            }
        }

        return false;
    }
}
