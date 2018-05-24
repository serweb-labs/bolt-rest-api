<?php

namespace Bolt\Extension\SerWeb\Rest\Services\Vendors;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Bolt\Pager;
use Cocur\Slugify\Slugify;
use Bolt\Translation\Translator as Trans;
use Bolt\Extension\SerWeb\Rest\DataFormatter;
use Bolt\Extension\SerWeb\Rest\Directives\RelatedDirective;
use Carbon\Carbon;
use Bolt\Extension\Serweb\Subscriptions\SubscriptionsExtension;

/**
 * Rest Controller class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class JsonApi 
{
    /** @var array The extension's configuration parameters */
    private $config;
    private $app;
    private $user;
    private $vendor;
    private $params;

    /**
     * Initiate the controller with Bolt Application instance and extension config.
     *
     * @param array $config
     */

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Abort: response wrapper
     *
     * @param array $data
     * @param int $code
     *
     * @return response
     */

    public function abort($data, $code)
    {
        return $this->app['rest.response']->response(array("message" => $data), $code);
    }

    /**
     * Parse parameters by request and configurationn
     *
     * @param Request $request
     * @param string $ct
     * 
     * @return void
     */
    private function getParams(Request $request, $ct = false)
    {   
        $ct = $ct ? : $request->get("contenttype");
        $contenttype = $this->app['storage']->getContentType($ct);
      
        return array(
            "query" => $this->digestQuery($request->get('filter', [])),
            "postfilter" =>  $this->digestPostfilter($request->get('filter', [])),
            "fields" => $request->get('fields', []),
            "include" => $this->digestInclude($request->get('include', "")),
            "pagination" => $this->digestPagination($request->get('page', null)),
            "sort" => $request->get('sort', $this->config['default-options']['sort']),
            "contenttype" => $contenttype,
            "ct" => $contenttype['slug']
        );
    }

    /**
     * parse query
     *
     * @param array $filter
     * 
     * @return array
     */
    private function digestQuery($filter)
    {   
        $query = array(
            "status" => empty($filter['status']) ? $this->config['default-query']['status'] : $filter['status'],
            "contain" => empty($filter['contain']) ? $this->config['default-query']['contain'] : $filter['contain'],
        );

        foreach ($filter as $key => $value) {
            if (in_array($key, $this->config['filter-fields-available'])) {
                if (!in_array($key, $query)) {
                    $query[$key] = $value;
                }
            }
        }
        return $query;
    }

    /**
     * parse post-filters
     *
     * @param array $filter
     * 
     * @return array
     */
    private function digestPostfilter($filter)
    {
        $query = array(
            "related" => empty($filter['related']) ? $this->config['default-postfilter']['related'] : $filter['related'],
            "unrelated" => empty($filter['unrelated']) ? $this->config['default-postfilter']['unrelated'] : $filter['unrelated'],
            "deep" => empty($filter['deep']) ? $this->config['default-postfilter']['deep'] : $filter['deep'],
        );
        return $query;
    }

    /**
     * Make pagination object
     *
     * @param array $pager
     * 
     * @return stdClass
     */
    private function digestPagination($pager)
    {
        $pagination = new \stdClass();
        $pagination->page = $pager['number'] ? : $this->config['default-options']['page'];
        $pagination->limit = $pager['size'] ? : $this->config['default-options']['limit'];
        return $pagination;
    }


    /**
     * Parse string of include param
     *
     * @param string $rawInclude
     * 
     * @return array
     */
    private function digestInclude($rawInclude)
    {
        $include = preg_split('/,/', $rawInclude, null, PREG_SPLIT_NO_EMPTY);
        return $include;
    }


    /**
     * Get multiple content action in the Rest API controller
     *
     * @param string $contenttypeslug
     *
     * @return abort|response
     */

    public function listContent($config)
    {
        $this->config = $config;
        $request = $this->app['request'];        
        $params = $this->getParams($request);
        
        // Rest best practices: allow only plural version of resource
        if ($params['ct'] !== $request->get('contenttype')) {
            return $this->abort("Page not found.", Response::HTTP_NOT_FOUND);
        }
        
        // @TODO: maybe in controller
        // If the contenttype is 'viewless', don't show the record page.
        if (isset($params['contenttype']['viewless']) &&
        $params['contenttype']['viewless'] === true) {
            return $this->abort("Page not found.", Response::HTTP_NOT_FOUND);
        }
        
        // get repo
        $repo = $this->app['storage']->getRepository($params['ct']);
        $deep = ($params['postfilter']['deep']) ? true : false;
        $related = ($params['postfilter']['related']) ? true : false;
        $unrelated = ($params['postfilter']['unrelated']) ? true : false;    
        
        $content = $this->fetchContent($params);

        // postfilter
        if ($deep || $related || $unrelated) {
            $content = $this->postFilter($content['content'], $params);
        } 

        // pagination
        $headers = array(
            'X-Total-Count' => $content['count'],
            'X-Pagination-Page' => $params['pagination']->page,
            'X-Pagination-Limit' => $params['pagination']->limit,
            );
        
        $formatter = new DataFormatter($this->app, $config, $params);
        $formatter->count = $content['count'];
        $data = $formatter->listing($params['contenttype'], $content['content']);
            
        return $this->app['rest.response']->response($data, 200, $headers);
    }

    /**
     * fetchContent
     *
     * @param array $params
     * @return array
     */
    private function fetchContent($params)
    {   
        $options = [];
        $q = $params['query'];

        
        if ($q['contain']) {
            $filter =  new \stdClass();
            $filter->term = $q['contain'];
            $app = $this->app;
            $filter->getFields = function ($ct) use ($app) {
                return array_keys($app['storage']->getContentType($ct)['fields']);
            };
            // todo: "_" It is not consistant in all Bolt versions: normalize
            $options["filter"] = $filter;
        }
        
        if ($params['sort']) {
            $options["order"] = $params['sort'];
        }
        
        if ($params['postfilter']['deep'] || $params['ct'] == 'search') {
            $contenttypes = implode(",", array_keys($this->app['config']->get('contenttypes')));
        }
        else {
            if ($params['pagination']) {
                $options["pagination"] = $params['pagination'];
            }
            $contenttypes = $params['ct'];
        }
        
        unset(
            $q['contain']
        );
        
        // aditional queries
        $options = (count($q) > 0) ? array_merge((array) $options, (array) $q) : $options;
        
        $results = $this->app['query']->getContent(
            $contenttypes,
            $options
        );

        // pagination
        if (isset($options["pagination"])) {
            $count = call_user_func($options['pagination']->count);
        }
        else {
            $count = null;
        }
        return array("content" => $results, "count" => $count);
    }

    /**
     * Takes a collection of content and 
     * filters them according to the criteria
     *
     * @param store $content
     * @param array $params
     * 
     * @return array
     */
    private function postFilter($content, $params)
    {   
        $deep = $params['postfilter']['deep'];
        $related = $params['postfilter']['related'] ? : null;
        $unrelated = $params['postfilter']['unrelated'] ? : null;
        $ct = $params['ct'];
        $ids = [];
        $load = [];
        $matched = [];

        // fetch all
        if ($deep) {
            foreach ($content as $key => $item) {
                if ($item->contenttype['slug'] == $ct) {

                    // if is some contenttype
                    if (in_array($item->id, $ids)) {
                        continue;
                    }

                    $matched[] = $item;
                    $ids[] = $item->id;
                }
            }           

            foreach ($content as $key => $item) {
                if ($item->contenttype['slug'] != $ct) {
                    $load = array_merge($load, $this->getBidirectionalRelations($item, $ct));
                }
            }
            $load = array_diff($load, $ids);
            if (!empty($load)) {
                $all = $this->app['query']->getContent($ct, array('id' => implode(" || ", $load)));
                $all->add($matched);
            }
            else {
                $all = $matched;
            }
            
        }
        else {
            $all = $this->toArray($content);
        }
        
        // positive related filter
        if (isset($related)) {
            $all = $this->positiveRelatedFilter($all, $related);
        }
        
        // negative related filte
        if (isset($unrelated)) {
            $all = $this->negativeRelatedFilter($all, $unrelated);
        }
        
        //pagination
        $partial = $this->paginate($all, $params['pagination']->limit, $params['pagination']->page);
        $count = count($all);
        
        return array("content" => $partial, "count" => $count);
    }

    /**
     * Get ids of all related content in two ways
     *
     * @param Content $item
     * @param string $related
     * @param int $id
     * 
     * @return arrayy
     */
    public function getBidirectionalRelations($item, $related, $id = null) {
        $rels = $item->relation->getField($related, true, $item->contenttype['slug'], $id);
        $ids = [];
        foreach ($rels as $rel) {
            if ($rel['from_contenttype'] == $related) {
                $ids[] = $rel['from_id'];
            } else {
                // to relation
                $ids[] = $rel['to_id'];
            }                
        }
        return $ids;
    }

     /**
     * View Content Action in the Rest API controller
     *
     * @param string            $contenttypeslug
     * @param string|integer    $slug integer|string
     *
     * @return abort|response
     */

    public function readContent($contenttypeslug, $slug, $config)
    {   
        $this->config = $config;
        $contenttype = $this->app['storage']->getContentType($contenttypeslug);
        $isSoft = $this->config['delete']['soft'];
        $softStatus = $this->config['delete']['status'];
        $request = $this->app['request'];
        $params = $this->getParams($request);

        // Rest best practices: allow only plural version of resource
        if ($contenttype['slug'] !== $contenttypeslug) {
            return $this->abort("Page $contenttypeslug not found.", Response::HTTP_NOT_FOUND);
        }

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            return $this->abort(
                "Page $contenttypeslug/$slug not found.",
                Response::HTTP_NOT_FOUND
            );
        }

        $slug = $this->app['slugify']->slugify($slug);

        // First, try to get it by slug.
        $content = $this->app['storage']->getContent($contenttype['slug'], array('slug' => $slug, 'returnsingle' => true, 'log_not_found' => !is_numeric($slug)));

        if (!$content && is_numeric($slug)) {
            // And otherwise try getting it by ID
            $content = $this->app['query']->getContent($contenttype['slug'], array('id' => $slug, 'returnsingle' => true));
        }

        // No content, no page!
        if (!$content) {
            return $this->abort(
                "Page $contenttypeslug/$slug not found.",
                Response::HTTP_NOT_FOUND
            );
        }

        // format
        $formatter = new DataFormatter($this->app, $config, $params);
        $map = $formatter->one($content);

        // todo: move to DataFormatter
        $data = $map;
        unset($data['data']['links']);
        unset($data['data']['metadata']);

        return $this->app['rest.response']->response($data, 200);
    }

    /**
     * View Content Action in the Rest API controller
     *
     * @param string            $contenttypeslug
     * @param string|integer    $slug integer|string
     *
     * @return abort|response
     */

     public function relatedContent($contenttypeslug, $slug, $relatedct, $config)
     {
         $this->config = $config;        
         $contenttype = $this->app['storage']->getContentType($contenttypeslug);
         $isSoft = $this->config['delete']['soft'];
         $softStatus = $this->config['delete']['status'];
         $request = $this->app['request'];         
         $params = $this->getParams($request, $relatedct);
 
         // Rest best practices: allow only plural version of resource
         if ($contenttype['slug'] !== $contenttypeslug) {
             return $this->abort("Page $contenttypeslug not found.", Response::HTTP_NOT_FOUND);
         }
 
         // If the contenttype is 'viewless', don't show the record page.
         if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
             return $this->abort(
                 "Page $contenttypeslug/$slug not found.",
                 Response::HTTP_NOT_FOUND
             );
         }
 
        $slug = $this->app['slugify']->slugify($slug); 
        $repo = $this->app['storage']->getRepository($contenttype['slug']);
        $content = $repo->find($slug);

         // No content, no page!
        if (!$content) {
            return $this->abort(
                "Page $contenttypeslug/$slug not found.",
                Response::HTTP_NOT_FOUND
            );
        }
        
        // get all relations
        $ids = $this->getBidirectionalRelations($content, $relatedct, $slug);        
        
        // offset
        $ids = $this->paginate($ids, $params['pagination']->limit, $params['pagination']->page);
        
        if (count($ids) > 0) {
            $content = $this->app['query']->getContent($relatedct, array( 'status' => $params['query']['status'], 'id' => implode(" || ", $ids)));
            $formatter = new DataFormatter($this->app, $config, $params);
            $formatter->count = $content->count();
            $data = $formatter->listing($relatedct, $content);
        }
        else {
            $data = array(
                "data" => [],
                "meta" => [
                    "count" => 0,
                    "page" => 1,
                    "limit" => $params['pagination']->limit
                ],
            
            );
        }
        return $this->app['rest.response']->response($data, 200);
     }

     public function loadRelatedContent($content, $relatedct, $slug, $status) {
        $ids = $this->getBidirectionalRelations($content, $relatedct, $slug);        
        $fetch = $this->app['query']->getContent($relatedct, array('status' => $status, 'id' => implode(" || ", $ids)));
        return $this->toArray($fetch);
    }

     
    /**
     * Insert Action: proccess create or update
     *
     * @param content $content
     * @param string $contenttypeslug
     * @param string $oldStatus
     * @param repository $repo
     *
     * @return response
     */

    public function insertAction($content, $contenttypeslug, $oldStatus, $repo)
    {
        $request = $this->app['request'];
        $contenttype = $this->app['storage']->getContentType($contenttypeslug);
        
        // Add non successfull control values to request values
        // http://www.w3.org/TR/html401/interact/forms.html#h-17.13.2
        // Also do some corrections
        $requestAll = $request->request->all()['data'];

        foreach ($contenttype['fields'] as $key => $values) {
            if (isset($requestAll[$key])) {
                switch ($values['type']) {
                    case 'float':
                        // We allow ',' and '.' as decimal point and need '.' internally
                        $requestAll[$key] = str_replace(',', '.', $requestAll[$key]);
                        break;
                }
            } else {
                switch ($values['type']) {
                    case 'select':
                        if (isset($values['multiple']) && $values['multiple'] === true) {
                            $requestAll[$key] = array();
                        }
                        break;
                    case 'checkbox':
                        $requestAll[$key] = 0;
                        break;
                    // default values prevent
                    // sql errors
                    case 'float':
                        $requestAll[$key] = 0;
                        break;
                    case 'integer':
                        $requestAll[$key] = 0;
                        break;
                }
            }
        }

        
        // assign values
        foreach ($contenttype['fields'] as $key => $values) {
            if (array_key_exists($key, $requestAll)) {
                $content[$key] = $requestAll[$key];
            }
        }

        // status
        $defaultStatus = $contenttype['default_status'] == "publish" ? 'published' : $contenttype['default_status'];
        $fallbackStatus = $contenttype['default_status'] ? $defaultStatus: 'published';
        $beforeStatus = $oldStatus ?: $fallbackStatus;

        if (array_key_exists('status', $requestAll)) {
            $newStatus = $requestAll['status'];
        } else {
            $newStatus = $beforeStatus;
        }

        $status = $this->app['users']->isContentStatusTransitionAllowed(
            $beforeStatus,
            $newStatus,
            $contenttype['slug'],
            $content['id']
        );
        
        if (!$status) {
            $error["message"] = Trans::__("Error processing the request");
            $this->abort($error, 500);
        }
        
        $content->status = $newStatus;
        
        // datepublish
        if (array_key_exists('datepublish', $requestAll)) {
            $datepublishStr = $requestAll['datepublish'];
            $time = Carbon::parse($datepublishStr);            
            $content->datepublish = new \DateTime($time);
        }
        
        // set owner id
        $content['ownerid'] = $this->user['id'];

        // slug: When storing, we should never have an empty slug/URI.
        if (!$content['slug'] || empty($content['slug'])) {
            $content['slug'] = 'slug-' . md5(mt_rand());
        }

        $content->setDatechanged('now');

        if (isset($requestAll['relation'])) {
            $relation = $requestAll['relation'];
        } else {
            $relation = [];
        }

        $values = array('relation' => $relation);
        $arr = [];

        if ($values['relation']) {
            foreach ($values['relation'] as $key => $value) {
                if (!is_array($value)) {
                    $values['relation'][$key] = array((string)trim($value));
                }
                else {
                    foreach ($value as $item) {
                        $arr[$key][] = ((string)trim($item));
                    }
                    $values['relation'] = $arr;
                }
            }            
        }

        /** @var Collection\Relations $related */
        $related = $this->app['storage']->createCollection('Bolt\Storage\Entity\Relations');
        $related->setFromPost($values, $content);
        $content->setRelation($related);


        // add note if exist
        $note = $request->request->get('note');

        if ($note && array_key_exists('notes', $contenttype['fields'])) {
            $notes = json_decode($content['notes'], true);
            if (!array_key_exists('data', $notes)) {
                $notes['data'] = array();
            }
            $date = new \DateTime('now');
            $date = $date->format('Y-m-d');
            $notes['data'][] = array('data' => $note, 'date' =>  $date);
            $content['notes'] = json_encode($notes);
        }


        // save
        $result = $repo->save($content);
        
        // get ID
        $slug = $content->getId();

        // $result;
        if (!$result) {
            $error["message"] = Trans::__("Error processing the request");
            $this->abort($error, 500);
        }

        $responseData = array('id' => $slug);

        $location = sprintf(
            '%s%s/%s%s',
            $this->app['paths']['canonical'],
            $this->config['endpoints']['rest'],
            $contenttypeslug,
            $slug
        );

        // Defalt headers
        $headers = array();

        // Detecting whether the answer is " created "
        if ($oldStatus == "") {
            $headers = array('Location' => $location);
            $code = 201;
        } else {
            $code = 200;
        }

        return $this->app['rest.response']->response($responseData, $code, $headers);
    }


    /**
     * Add Content Action in the Rest API controller
     *
     * @param string  $contenttypeslug The content type slug
     *
     * @return insertAction
     */

    public function createContent($contenttypeslug, $config)
    {   
        $this->config = $config;
        $content = $this->app['storage']->getContentObject($contenttypeslug);
        $repo = $this->app['storage']->getRepository($contenttypeslug);

        //set defaults
        $content = $repo->create(
            array(
                'contenttype' => $contenttypeslug,
                'datepublish' => date('Y-m-d'),
                'datecreated' => date('Y-m-d'),
                'status' => 'published'
             )
        );

        return $this->insertAction($content, $contenttypeslug, "", $repo);
    }

    /**
     * Update Content Action in the Rest API controller
     *
     * @param string $contenttypeslug The content type slug
     * @param integer|string $slug The slug of content
     *
     * @return insertAction
     */

    public function updateContent($contenttypeslug, $slug, $config)
    {   
        $this->config = $config;       
        
        $repo = $this->app['storage']->getRepository($contenttypeslug);
        $content = $repo->find($slug);
        $oldStatus = $content->status;
        return $this->insertAction($content, $contenttypeslug, $oldStatus, $repo);
    }

    /**
     * Delete Content Action in the Rest API controller
     *
     * @param string            $contenttypeslug The content type slug
     * @param integer|string    $slug The slug of content
     *
     * @return response
     */

    public function deleteContent($contenttypeslug, $slug, $config)
    {
        $this->config = $config;   
        $isSoft = $this->config['delete']['soft'];
        $status = $this->config['delete']['status'];        
        $contenttype = $this->app['storage']->getContentType($contenttypeslug);
        $repo = $this->app['storage']->getRepository($contenttype['slug']);
        $row = $repo->find($slug);

        if ($isSoft) {
            $row->status = $status;
            $repo->save($row);
            $result = true;
        } else {
            $result = $repo->delete($row);
        }

        $content = array('action' => $result);
        return $this->app['rest.response']->response($content, 204);
    }


    // filter by related (ex. where {related: "book:1,2,3"})
    public function positiveRelatedFilter($partial, $related)
    {   
        $partial = $this->toArray($partial);
        $rel = explode(":", $related);
        $relations = [];

        if (count($rel) > 1) {
            $relations = explode(",", $rel[1]);
        }

        foreach ($partial as $key => $item) {
            $detect = false;
            $ids = $this->getBidirectionalRelations($item, $rel[0]);
            
            if (!empty($ids)) {
                if (count($relations) == 0) {
                    $detect = true;
                } else if (count(array_intersect($ids, $relations)) > 0) {
                    $detect = true;
                }
            }
            
            if (!$detect) {
                unset($partial[$key]);
            }
        }
        return $partial;
    }


    /**
     * Exclude those that are related to a certain type of content
     *
     * @param array | collection $partial
     * @param string $unrelated
     * 
     * @return array
     */
    private function negativeRelatedFilter($partial, $unrelated)
    {
        $rel = explode(":", $unrelated);
        $relations = [];
        $except = [];
        $list = [];

        if (count($rel) > 1) {
            $relations = explode(",", $rel[1]);
        }

        foreach ($relations as $value) {
            if (strpos($value, "!") !== false) {
                $except[] = str_replace("!", "", $value);
                continue;
            }
            $list[] = $value;
        }

        foreach ($partial as $key => $item) {
            $detect = false;
            $ids = $this->getBidirectionalRelations($item, $rel[0]);
            if (!empty($ids)) {
                if (count($list) == 0) {
                    $detect = true;                    
                    if (count(array_intersect($ids, $except)) > 0) {
                        $detect = false;
                    }
                } else if (count(array_intersect($ids, $list)) > 0) {
                    $detect = true;
                }
            }
              
            if ($detect) {
                unset($partial[$key]);
            }
        }
        
        return $partial;
    }



     /**
      * Pagination helper
      *
      * @param array $arr
      * @param int $limit
      * @param int $page

      * @return array
      */
    private function paginate($arr, $limit, $page)
    {   
        if (!is_array($arr)) {
            $arr = $this->toArray($arr);
        }
        $to = ($limit) * $page;
        $from = $to - ($limit);
        return array_slice($arr, $from, $to);
    }
    
    /**
     * Iterable to aray
     *
     * @param collection | object | array $el
     * 
     * @return array
     */
    private function toArray($el)
    {
        if (is_array($el)) { 
            return $el;
        }
        $arr = [];
        foreach ($el as $key => $value) {
            $arr[] = $value;
        }

        return $arr;
    }

    /**
     * Check interception
     *
     * @param array $array1
     * @param array $array2
     * 
     * @return bool
     */
    private function intersect($array1, $array2)
    {
        $result = array_intersect($array1, $array2);
        $q = count($result);

        if ($q > 0) {
            return true;
        }
        
        return false;
    }

}
