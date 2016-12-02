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
           // dump($request->request->all); exit();
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

        $request = $this->app['request'];
        $filter = $request->get('filter');
        $where = $request->get('where');

        // If the contenttype is 'viewless', don't show the record page.
        if (isset($contenttype['viewless']) && $contenttype['viewless'] === true) {
            return $this->abort("Page $contenttypeslug not found.", Response::HTTP_NOT_FOUND);
        }
                
        $pagerid = Pager::makeParameterId($contenttypeslug);
        /* @var $query \Symfony\Component\HttpFoundation\ParameterBag */
        $query = $this->app['request']->query;
        // First, get some content
        $page = $query->get($pagerid, $query->get('page', 1));
        $amount = (!empty($contenttype['listing_records']) ? $contenttype['listing_records'] : $this->app['config']->get('general/listing_records'));
        $sort = (!empty($contenttype['sort']) ? $contenttype['sort'] : $this->app['config']->get('general/listing_sort'));
        $order = $request->get('order', $sort);

        // If is allowed show unpublished and drafted content
        $allow = $this->app['permissions']->isAllowed('contenttype:' . $contenttypeslug . ':edit', $this->config['user']);

        if ($allow) {
            $status = "published";
        } else {
            $status = "published || draft || held";
        }

        $options = array(
            'limit' => $amount,
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
        

            /** "where" paremeter only work in JSON STANDARD format,
            * Ex. in twig {% setcontent mypages = 'pages' where { datepublish: '>today' } %} in JSON { "datepublish": ">today" }
            * ever whith double quotes.    
            */

            $getwhere = json_decode($where);
            $options = ($getwhere) ? array_merge((array) $options, (array) $getwhere) : $options;
        }

        $content = $this->app['storage']->getContent($contenttype['slug'], $options);



        // get total count
        $allopt = $options;
        $allopt['limit'] = null;
        $allopt['page'] = null;
        $allopt['paging'] = false;

        $all = $this->app['storage']->getContent(
                $contenttype['slug'],
                $allopt
            );

        $count = count($all);
        $headers = array(
            'X-Total-Count' => $count,
            'X-Pagination-Page' => $options['page'],
            'X-Pagination-Limit' => $options['limit'],
            );


        $formatter = new DataFormatter($this->app);
        $map = $formatter->dataList($options['contenttype'], $content);
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
        $data = array("data" => $map);


        return $this->app['rest.response']->response($data, 200);
    }

    /**
     * Insert Action: proccess create or update
     * 
     * @param content $content 
     * @param string $contenttypeslug 
     * @param string $oldStatus 
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
                }
            }
        }

        // assign values
        foreach ($contenttype['fields'] as $key => $values) {
            if (array_key_exists($key, $requestAll)) {
                $content[$key] = $requestAll[$key];
            }
        }

        $newStatus = $content['status'];

        // Save the record, and return to the overview screen, or to the record (if we clicked 'save and continue')
        $status = $this->app['users']->isContentStatusTransitionAllowed($oldStatus, $newStatus, $contenttype['slug'], $id);

        if (!$status) {
            $error["message"] = Trans::__("Error processing the request");
            $this->abort($error, 500);
        }

        // Get the associate record change comment
        $comment = $request->request->get('changelog-comment');

        // set owner id
        $content['ownerid'] = $this->user['id'];
        $content->setDatechanged('now');
        $values = array('relation' => $request->request->get('relation'));    

        foreach($values['relation'] as $key => $value) {
            if (!is_array($value)) {
                $bar = $value . "";
                $values['relation'][$key] = array(trim($bar));
            } 
        }
        
        $values['relation']['consumptions'] = array('2168');
        
        $related = $this->app['storage']->createCollection('Bolt\Storage\Entity\Relations');
        $related->setFromPost($values, $content);
        $content->setRelation($related);
        
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
            array('contenttypeslug' => $contenttypeslug,
            'slug' => $slug),
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
     * @param string    $contenttypeslug The content type slug
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
        $oldStatus = $content['status'];

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

        $contenttype = $this->app['storage']->getContentType($contenttypeslug);

        $result = $this->app['storage']->deleteContent($contenttype['slug'], $slug);
        
        $content = array('action' => $result);

        return $this->app['rest.response']->response($content, 204);

    }

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
}
