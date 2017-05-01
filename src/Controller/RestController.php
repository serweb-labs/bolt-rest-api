<?php

namespace Bolt\Extension\SerWeb\Rest\Controller;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Bolt\Pager;
use Cocur\Slugify\Slugify;
use Bolt\Translation\Translator as Trans;
use Bolt\Extension\SerWeb\Rest\DataFormatter;

/**
 * Rest Controller class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class RestController implements ControllerProviderInterface
{
    /** @var array The extension's configuration parameters */
    private $config;
    private $app;
    private $user;

    /**
     * Initiate the controller with Bolt Application instance and extension config.
     *
     * @param array $config
     */

    public function __construct(array $config, Application $app)
    {
        $this->config = $config;
        $this->app = $app;
    }

    /**
     * Specify which method handles which route.
     *
     * @param Application $app An Application instance
     *
     * @return ControllerCollection A ControllerCollection instance
     */

    public function connect(Application $app)
    {

        /** @var $ctr \Silex\ControllerCollection */
        $ctr = $this->app['controllers_factory'];
        
        $ctr->get('/{contenttypeslug}', array($this, 'readMultipleContentAction'))
            ->value('action', 'view')
            ->bind('readmultiplecontent')
            ->before(array($this, 'before'));

        $ctr->options('/{contenttypeslug}', array($this, 'corsResponse'))
            ->bind('multiplecontentOptions');

        $ctr->get('/{contenttypeslug}/{slug}', array($this, 'readContentAction'))
            ->value('action', 'view')
            ->bind('readcontent')
            ->before(array($this, 'before'));

        $ctr->post('/{contenttypeslug}', array($this, 'createContentAction'))
            ->value('action', 'create')
            ->bind('createcontent')
            ->before(array($this, 'before'));

        $ctr->patch('/{contenttypeslug}/{slug}', array($this, 'updateContentAction'))
            ->value('action', 'edit')
            ->bind('updatecontent')
            ->before(array($this, 'before'));

        $ctr->delete('/{contenttypeslug}/{slug}', array($this, 'deleteContentAction'))
            ->value('action', 'delete')
            ->bind('deletecontent')
            ->before(array($this, 'before'));

        $ctr->options('/{contenttypeslug}/{slug}', array($this, 'corsResponse'))
            ->bind('contentOptions');

        return $ctr;
    }


    /**
     * Before functions in the Rest API controller
     *
     * @param Request $request The Symfony Request
     *
     * @return abort|null
     */

    public function before(Request $request)
    {
        // Get User
        $user = $this->app['users']->getUser($this->app['rest']->getUsername());

        // Set User
        $this->user = $user;

        // Check permissions
        $contenttype = $request->get("contenttypeslug");
        $slug = $request->get("slug") ? ":" . $request->get("slug") : "";
        $action = $request->get("action");
        $what = "contenttype:" . $contenttype . ":" . $action . $slug;

        $allow = $this->app['permissions']->isAllowed($what, $user);

        // Is allow?
        if (!$allow) {
            $error = Trans::__("You don't have the correct permissions");
            return $this->abort($error, Response::HTTP_FORBIDDEN);
        }
        
        // Get expected content type
        $mime = $request->headers->get('Content-Type');

        // Parsing request in json
        if (0 === strpos($mime, 'application/json') || 0 === strpos($mime, 'application/merge-patch+json')) {
            $data = json_decode($request->getContent(), true);
            $request->request->replace(is_array($data) ? $data : array());
        }

        return null;
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
     * Get multiple content action in the Rest API controller
     *
     * @param string $contenttypeslug
     *
     * @return abort|response
     */

    public function readMultipleContentAction($contenttypeslug)
    {
        $contenttype = $this->app['storage']->getContentType($contenttypeslug);
        
        // Rest best practices: allow only plural version of resource
        if ($contenttype['slug'] !== $contenttypeslug) {
            return $this->abort("Page $contenttypeslug not found.", Response::HTTP_NOT_FOUND);
        }

        // get repo
        $repo = $this->app['storage']->getRepository($contenttypeslug);


        $request = $this->app['request'];
        $filter = $request->get('filter');
        $where = $request->get('where', []);
        $fields = $request->get('fields', false);
        $limit = $request->get('limit', false);
        $deep = $request->get('deep', false);
        $isSoft = $this->config['delete']['soft'];
        $softStatus = $this->config['delete']['status'];


        // limite fields to:
        if ($fields) {
            $fieldsarr = explode(",", $fields);
        } else {
            $fieldsarr = false;
        }

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            return $this->abort("Page $contenttypeslug not found.", Response::HTTP_NOT_FOUND);
        }
                
        $pagerid = Pager::makeParameterId($contenttypeslug);
        /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $this->app['request']->query;
        // First, get some content
        $page = $query->get($pagerid, $query->get('page', 1));

        if (!$limit) {
            $limit = (!empty($contenttype['listing_records']) ? $contenttype['listing_records'] : $this->app['config']->get('general/listing_records'));
        }

        $sort = (!empty($contenttype['sort']) ? $contenttype['sort'] : $this->app['config']->get('general/listing_sort'));
        $order = $request->get('order', $sort);

        // If is allowed show unpublished and drafted content
        $allow = $this->app['permissions']->isAllowed('contenttype:' . $contenttypeslug . ':edit', $this->config['user']);

        if ($allow) {
            $status = "published || draft || held";
        } else {
            $status = "published";
        }

        if ($this->config['only_published']) {
            $status = "published";
        }
        

        $options = array(
            'limit' => $limit,
            'order' => $order,
            'page' => $page,
            'paging' => true,
            'status' => $status
        );


        // options filters in url get parameters
        if ($this->config['params'] === true) {
            if ($order) {
                if (!preg_match('/^(-?[a-zA-Z][a-zA-Z0-9_\\-]*)\\s*(ASC|DESC)?$/', $order, $matches)) {
                    return $this->abort('Invalid request', 400);
                }
                $options['order'] = $order;
            } else {
                $options['order'] = $this->app['config']->get('general/listing_sort');
            }
           
            $options['filter'] = $filter ? $filter : false;
        
            $options = ($where) ? array_merge((array) $options, (array) $where) : $options;
        }


        $partial = $this->app['storage']->getContent(
            $contenttype['slug'],
            $options
        );

        // get total count
        $allopt = $options;
        $allopt['limit'] = null;
        $allopt['page'] = null;
        $allopt['paging'] = false;

        // fetch all
        $all = $this->app['storage']->getContent(
            $contenttype['slug'],
            $allopt
        );

        $ids = [];
        
        foreach ($all as $item) {
            $ids[] = $item['id'];
        }

        // fetch deep search
        if ($deep && $options['filter']) {
            $searchresults = $this->app['storage']->searchContent($options['filter']);
            $matched = [];

            foreach ($searchresults['results'] as $key => $item) {
                if (!in_array($contenttype, $item->relation)) {
                    foreach ($item->relation[$contenttypeslug] as $value) {
                        if (in_array($value, $ids)) {
                            continue;
                        }

                        // get one and push
                        $query = $options;
                        $query['id'] = $value;
                        $query['returnsingle'] = true;
                        $query['status'] = $options['status'];

                        // unset unused keys
                        unset(
                            $query['filter'],
                            $query['limit'],
                            $query['page'],
                            $query['paging'],
                            $query['order']
                        );

                        $result = $this->app['storage']->getContent($contenttype['slug'], $query);
                        
                        if ($result) {
                            $matched[] = $result;
                            $ids[] = $value;
                        }
                    }
                }
            }
            $all = array_merge($all, $matched);

            $partial = $this->paginate($all, $options['limit'], $options['page']);
        }



        // filter by related (ex. where {related: "book:1,2,3"})
        if (array_key_exists('related', $allopt) && !empty($allopt['related'])) {
            $rel = explode(":", $allopt['related']);
            $relations = explode(",", $rel[1]);
            foreach ($partial as $key => $item) {
                $detect = 0;
                foreach ($relations as $value) {
                    if (in_array($value, $item->relation[$rel[0]])) {
                        $detect = 1;
                    }
                }

                if ($detect == 0) {
                    unset($partial[$key]);
                }
            }
        }

        // Exclude those that are related to a certain type of content
        // (ex. where {norelated: "book"})
        if (array_key_exists('norelated', $allopt) && !empty($allopt['norelated'])) {
            $norelated = explode("!", $allopt['norelated']);
            $ct = $norelated[0];
            $ignore = $norelated[1];

            foreach ($partial as $key => $item) {
                if ($item->relation[$ct] != null) {
                    if (in_array($ignore, $item->relation[$ct])) {
                        $detect = 1;
                    } elseif ($isSoft) {
                        $repo = $this->app['storage']->getRepository($ct);
                        foreach ($item->relation[$ct] as $relatedId) {
                            $content = $repo->find($relatedId);

                            if ($content['status'] == $softStatus) {
                                $detect = 1;
                            } else {
                                $detect = 0;
                                break;
                            }
                        }
                        $content = $repo->find($item->relation[$ct][0]);
                    } else {
                        $detect = 0;
                    }
                } else {
                    $detect = 1;
                }
                    
                if ($detect == 0) {
                    unset($partial[$key]);
                }
            }
        }

        // pagination
        $count = count($all);

        $headers = array(
            'X-Total-Count' => $count,
            'X-Pagination-Page' => $options['page'],
            'X-Pagination-Limit' => $options['limit'],
            );


        $formatter = new DataFormatter($this->app, $fieldsarr);
        $map = $formatter->dataList($options['contenttype'], $partial);


        $data = array("data" => $map);

        return $this->app['rest.response']->response($data, 200, $headers);
    }


     /**
     * View Content Action in the Rest API controller
     *
     * @param string            $contenttypeslug
     * @param string|integer    $slug integer|string
     *
     * @return abort|response
     */

    public function readContentAction($contenttypeslug, $slug)
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

        $data = array("data" => $map);


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

    public function createContentAction($contenttypeslug)
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

    public function updateContentAction($contenttypeslug, $slug)
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

    public function deleteContentAction($contenttypeslug, $slug)
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

    /**
     * CORS: cross origin resourse sharing handler
     *
     * @return response
     */
    public function corsResponse()
    {
        if ($this->config["cors"]['enabled']) {
            $response = new Response();
            $response->headers->set(
                'Access-Control-Allow-Origin',
                $this->config["cors"]["allow-origin"]
            );
            $a = $this->config["security"]["jwt"]["request_header_name"] . ", X-Pagination-Limit, X-Pagination-Page, X-Total-Count, Content-Type";

            $methods = "GET, POST, PATCH, PUT, DELETE";

            $response->headers->set(
                'Access-Control-Allow-Headers',
                $a
            );

            $response->headers->set(
                'Access-Control-Allow-Methods',
                $methods
            );

            return $response;
        } else {
            return "";
        }
    }

    /**
     * Pagination helper
     *
     * @return response
     */
    public function paginate($arr, $limit, $page)
    {
        $to = ($limit - 1) * $page;
        $from = $to - ($limit - 1);
        return array_slice($arr, $from, $to);
    }
}
