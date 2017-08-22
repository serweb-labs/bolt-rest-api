<?php
namespace Bolt\Extension\SerWeb\Rest;

/**
 * DataFormatter library for content.
 *
 * @author Tobias Dammers <tobias@twokings.nl>
 * @author Luciano Rodriguez <info@serweb.com.ar>
 *
 */

use Symfony\Component\Debug\Exception\ContextErrorException;

class DataFormatter
{
    protected $app;
    public $fields;
    public $status;
    public $include;
    private $cache;
    private $queryCache;


    public function __construct($app, $config, $fields = false, $status = "published", $include = [])
    {
        $this->app = $app;
        $this->config = $config;
        $this->fields = $fields;
        $this->status = $status;
        $this->include = $include;
        $this->includes = [];
    }

    public function list($contenttype, $items)
    {
        if (!$this->isIterable($items)) {
            throw new \Exception("empty content");
        }

        if (empty($items)) {
            $items = array();
        }
        $all = [];
        foreach ($items as $item) {
            $all[] = $this->one($item);
        }


        return array(
            'links' => array(
                'self' => '',
                'next' => '',
                'last' => ''
                ),
             'meta' => array(),
             'data' => $all,
             'included' => $this->includesToArray($this->includes)
             );
    }

    private function one($item, $deep = true, $child = false)
    {
        $contenttype = $item->contenttype['slug'];

        //  allowed fields
        if ($this->fields[$contenttype]) {
            $allowedFields = explode(",", $this->fields[$contenttype]);
        } else {
            $allowedFields = false;
        }

        $fields = array_keys($contenttype['fields']);
        
        // Always include the ID in the set of fields
        array_unshift($fields, 'id');

        $fields = $item->contenttype['fields'];
        $values = array();

        foreach ($fields as $field => $value) {
            if ($allowedFields && !in_array($key, $allowedFields)) {
                continue;
            }
            $values[$field] = $item->{$field};
        }

        // metadata values
        if (!$allowedFields || in_array('ownerid', $allowedFields)) {
            $values['ownerid'] = $item->ownerid;
        }
        if (!$allowedFields|| in_array('datepublish', $allowedFields)) {
            $values['datepublish'] = $item->datepublish;
        }

        // Check if we have image or file fields present. If so, see if we need to
        // use the full URL's for these.
        if (isset($values[$key]['file'])) {
            foreach ($contenttype['fields'] as $key => $field) {
                if (($field['type'] == 'image' || $field['type'] == 'file') && isset($values[$key])) {
                    $values[$key]['url'] = sprintf(
                        '%s%s%s',
                        $this->app['paths']['canonical'],
                        $this->app['paths']['files'],
                        $values[$key]['file']
                    );
                }
                if ($field['type'] == 'image' && isset($values[$key]) && is_array($this->config['thumbnail'])) {
                    // dump($this->app['paths']);
                    $values[$key]['thumbnail'] = sprintf(
                        '%s/thumbs/%sx%s/%s',
                        $this->app['paths']['canonical'],
                        $this->config['thumbnail']['width'],
                        $this->config['thumbnail']['height'],
                        $values[$key]['file']
                    );
                }
            }
        }

        $relationship = [];

        // get explicit relations
        $cts = array_keys($item->contenttype['relations']);

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
            if ($this->config['soft-delete']['enable']) {
                $f = $this->getFromCache($rct, $rid);
                $deleted = ($f->status == 'draft');
                if ($deleted) {
                    continue;
                }
            }

            // @TODO: RFC
            // this return deleted and not published values :/
            $relationship[$rct] = array(
                "data" => array(),
                "links" => array()
            );

            $relationship[$rct]['data'][] = array('type' => $rct, 'id' => $rid);
            $relationship[$rct]['links'] = $this->getLinksRelated(
                array(
                    'type' => $rct,
                    'id' => $rid
                )
            );

            // @TODO: support "dot sintax" in include
            // follow JSON API specification
            if (in_array($rct, $this->include) && $deep) {
                if (!array_key_exists($ct, $this->includes)) {
                    $this->includes[$ct] = [];
                }

                if (!array_key_exists($rid, $this->includes[$rct])) {
                    $this->includes[$rct][$rid] = $this->one($this->getFromCache($rct, $rid), false, true);
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
        $protocol = stripos($_SERVER['SERVER_PROTOCOL'], 'https') === true ? 'https://' : 'http://';
        $host = $_SERVER[HTTP_HOST];
        $api = $this->config['endpoints']['rest'];
        $self = $protocol . $host . $api . "/" . $item->contenttype['slug'] . "/" . $item->id;
        return array(
            "self" => $self
            );
    }

    private function getLinksRelated($item)
    {
        return array("self" => "", "related" => "");
    }

    private function isIterable($var)
    {
        return (is_array($var) || $var instanceof \Traversable);
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
                return $this->app['query']->getContent($q);
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
