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

    // @TODO: allowed fields
    private function getParams(Request $request)
    {
        $contenttype = $this->app['storage']->getContentType($request->get("contenttype"));
        return array(
            "query" => $this->digestQuery($request->get('filter', [])),
            "fields" => $request->get('fields', []),
            "include" => $this->digestInclude($request->get('include', [])),
            "pagination" => $this->digestPagination($request->get('page', null)),
            "sort" => $request->get('sort', $this->config['default-options']['sort']),
            "contenttype" => $contenttype,
            "ct" => $contenttype['slug'],
        );
    }

    private function digestQuery($filter)
    {
        $query = array(
            "status" => $filter['status'] ? : $this->config['default-query']['status'],
            "related" => $filter['related'] ? : $this->config['default-query']['related'],
            "unrelated" => $filter['unrelated'] ? : $this->config['default-query']['unrelated'],
            "contain" => $filter['contain'] ? : $this->config['default-query']['contain'],
            "deep" => $filter['deep'] ? : $this->config['default-query']['deep'],
        );

        foreach ($filter as $key => $value) {
            if (in_array($key, $this->config['contenttypes']['filter-fields-available'])) {
                if (!in_array($key, $query)) {
                    $query[$key] = $value;
                }
            }
        }

        return $query;
    }

    private function digestPagination($pager)
    {
        $pagination = new \stdClass();
        $pagination->page = $pager['number'] ? : $config['default-options']['page'];
        $pagination->limit = $pager['size'] ? : $config['default-options']['limit'];
        return $pagination;
    }


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

    public function listContent(Request $request, $config)
    {
        $this->config = $config;
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


        $search = ($params['query']['deep']) ? true : false;
        $content = $search ? $this->getDeepContent($params) : $this->getSurfaceContent($params);

        // pagination
        $headers = array(
            'X-Total-Count' => $content['count'],
            'X-Pagination-Page' => $pager['number'],
            'X-Pagination-Limit' => $pager['size'],
            );

        $formatter = new DataFormatter($this->app, $config, $fields);
        $formatter->include = $params['include'];

        $formatter->status = $status;
        $data = $formatter->list($params['contenttype'], $content['content']);

        return $this->app['rest.response']->response($data, 200, $headers);
    }

    private function getSurfaceContent($params)
    {
        $options = [];
        $q = $params['query'];

        if ($params['pagination']) {
            $options["pagination"] = $params['pagination'];
        }

        if ($q['contain']) {
            $filter =  new \stdClass();
            $filter->term = $q['contains'];
            $filter->fields = ["title", "details", "state"];
            $filter->alias = $params['ct'];
            $options["filter"] = $filter;
        }

        if ($q['status']) {
            $options["status"] = $q['status'];
        }

        if ($q['related']) {
            $options["related"] = $q['related'];
        }

        if ($q['unrelated']) {
            $options["unrelated"] = $q['unrelated'];
        }

        unset(
            $q['contain'],
            $q['status'],
            $q['related'],
            $q['unrelated'],
            $q['deep']
        );
       
        $options = (count($q > 0)) ? array_merge((array) $options, (array) $q) : $options;

        $results = $this->app['query']->getContent(
            "${params['ct']}/latest",
            $options
        );

        // pagination
        $count = ($options['pagination']->count)();
       

        return array("content" => $results, "count" => $count);
    }

    private function getDeepContent($params)
    {
        // fetch all
        if ($deep && $filter) {
            $all = $this->deepSearch(
                array('status' => $status, 'filter' => $filter, 'limit' => 100000),
                $contenttypeslug
            );
        } else {
            $all = $this->toArray($this->app['storage']->getContent(
                $contenttypeslug,
                array('status' => $status, 'filter' => $filter, 'limit' => 100000)
            ));
        }

        // positive related filter
        if ($related) {
            $all = $this->positiveRelatedFilter($all, $related);
        }

        // negative related filte
        if ($norelated) {
            $all = $this->negativeRelatedFilter($all, $norelated);
        }

        //pagination
        $partial = $this->paginate($all, $params['pagination']->limit, $params['pagination']->page);
        $count = count($all);
    }

    private function deepSearch($options, $ct)
    {
        $matched = [];
        $ids = [];
        $searchresults = $this->app['storage']->searchContent($options['filter']);
        foreach ($searchresults['results'] as $key => $item) {
            if ($item->contenttype['slug'] == $ct) {
                // if is some contenttype
                if (in_array($item->id, $ids)) {
                    continue;
                }

                // get one and push
                $query = array(
                        'id' => $item->id,
                        'returnsingle' => true,
                        'status' => $options['status']
                    );

                $result = $this->app['storage']->getContent($ct, $query);

                if ($result) {
                    $matched[] = $result;
                    $ids[] = $item->id;
                }
            } else {
                foreach ($item->relation[$ct] as $value) {
                    if (in_array($value, $ids)) {
                        continue;
                    }

                    // get one and push
                    $query = array(
                        'id' => $value,
                        'returnsingle' => true,
                        'status' => $options['status']
                    );

                    $result = $this->app['storage']->getContent($ct, $query);

                    if ($result) {
                        $matched[] = $result;
                        $ids[] = $value;
                    }
                }
            }
        }
        
        return $matched;
    }

     /**
     * View Content Action in the Rest API controller
     *
     * @param string            $contenttypeslug
     * @param string|integer    $slug integer|string
     *
     * @return abort|response
     */

    public function readContent($contenttypeslug, $slug)
    {
        $contenttype = $this->app['storage']->getContentType($contenttypeslug);
        $isSoft = $this->config['delete']['soft'];
        $softStatus = $this->config['delete']['status'];

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
            $content = $this->app['storage']->getContent($contenttype['slug'], array('id' => $slug, 'returnsingle' => true));
        }

        // No content, no page!
        if (!$content) {
            return $this->abort(
                "Page $contenttypeslug/$slug not found.",
                Response::HTTP_NOT_FOUND
            );
        }


        // format
        $formatter = new DataFormatter($this->app);
        $map = $formatter->data($content);

        if ($isSoft) {
            $related = array();

            // soft delete detect
            foreach ($map['relation'] as $key => $value) {
                $related[$key] = array();
                $repo = $this->app['storage']->getRepository($key);
                foreach ($value as $id) {
                    $content = $repo->find($id);
                    if ($content['status'] != $softStatus) {
                        $related[$key][] = (string)$content['id'];
                    };
                }
            }
            $map['relation'] = $related;
        }

        $data = $map;


        return $this->app['rest.response']->response($data, 200);
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
        $requestAll = $request->request->all();

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
            $id
        );

        if (!$status) {
            $error["message"] = Trans::__("Error processing the request");
            $this->abort($error, 500);
        }

        $content->status = $newStatus;

        // datepublish
        if (array_key_exists('datepublish', $requestAll)) {
            $datepublishStr = $requestAll['datepublish'];
            $content->datepublish = new \DateTime($datepublishStr);
        }

        // set owner id
        $content['ownerid'] = $this->user['id'];

        // slug: When storing, we should never have an empty slug/URI.
        if (!$content['slug'] || empty($content['slug'])) {
            $content['slug'] = 'slug-' . md5(mt_rand());
        }

        $content->setDatechanged('now');

        $values = array('relation' => $request->request->get('relation'));

        if ($values['relation']) {
            foreach ($values['relation'] as $key => $value) {
                if (!is_array($value)) {
                    $bar = $value . "";
                    $values['relation'][$key] = array(trim($bar));
                }
            }

            $related = $this->app['storage']->createCollection('Bolt\Storage\Entity\Relations');
            $related->setFromPost($values, $content);
            $content->setRelation($related);
        }


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

        $location = $this->app['url_generator']->generate(
            'readcontent',
            array(
                'contenttypeslug' => $contenttypeslug,
                'slug' => $slug
                ),
            true
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

    public function createContent($contenttypeslug)
    {
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

    public function updateContent($contenttypeslug, $slug)
    {
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

    public function deleteContent($contenttypeslug, $slug)
    {
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


    private function positiveRelatedFilter($partial, $related)
    {
        // filter by related (ex. where {related: "book:1,2,3"})
        
        $rel = explode(":", $related);
        $relations = explode(",", $rel[1]);
        foreach ($partial as $key => $item) {
            $detect = false;
            foreach ($relations as $value) {
                if (is_array($item->relation[$rel[0]])) {
                    if (in_array($value, $item->relation[$rel[0]])) {
                        $detect = true;
                    }
                }
            }

            if (!$detect) {
                unset($partial[$key]);
            }
        }
        
        return $partial;
    }

    private function negativeRelatedFilter($partial, $val)
    {
        // Exclude those that are related to a certain type of content
        // (ex. where {norelated: "book"})
        $norelated = explode("!", $val);
        $ct = $norelated[0];
        $ignore = preg_split('/,/', $norelated[1], null, PREG_SPLIT_NO_EMPTY);
        foreach ($partial as $key => $item) {
            if ($item->relation[$ct] != null) {
                if ($this->intersect($ignore, $item->relation[$ct])) {
                    $detect = true;
                } elseif ($isSoft) {
                    $repo = $this->app['storage']->getRepository($ct);
                    foreach ($item->relation[$ct] as $relatedId) {
                        $content = $repo->find($relatedId);

                        if ($content['status'] == $softStatus) {
                            $detect = true;
                        } else {
                            $detect = false;
                            break;
                        }
                    }
                    $content = $repo->find($item->relation[$ct][0]);
                } else {
                    $detect = false;
                }
            } else {
                $detect = true;
            }

            if (!$detect) {
                unset($partial[$key]);
            }
        }
        
        return $partial;
    }


    /**
     * Pagination helper
     *
     * @return response
     */
    private function paginate($arr, $limit, $page)
    {
        $to = ($limit) * $page;
        $from = $to - ($limit);
        return array_slice($arr, $from, $to);
    }

    private function toArray($el)
    {
        if (!is_array($el)) {
            return [$el];
        }

        return $el;
    }

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
