<?php

namespace Bolt\Extension\SerWeb\Rest\Controller;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Bolt\Pager;
use Cocur\Slugify\Slugify;
use Bolt\Translation\Translator as Trans;
use Bolt\Events\AccessControlEvent;
use Bolt\Events\AccessControlEvents;

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
        $user = $this->app['users']->getUser($this->config['username']);

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
     * @param array $options 
     * 
     * @return response
     */

    public function abort($data, $code, $options = array())
    {

        return $this->response(array("message" => $data), $code, $options);

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

        $options = array(
            "single" => false,
            "isContent" => true,
            "contenttype" => $contenttype
        );

        return $this->response($content, 200, $options);

    }


    /**
     * Detect "Accept" head and proccess
     * 
     * @param array $data 
     * @param int $code 
     * 
     * @param array $options 
     * @param array $headers 
     * 
     * @return $this->$method|Symfony\Component\HttpFoundation\Response;
     */

    private function response($data, $code, $options = array(), $headers = array())
    {

        $default = 'application/json';

        if (empty($options["media"])) {
            $media = $this->app['request']->headers->get('Accept', $default);
        }
        
        $utilFragment = explode(";", $media);
        $acceptList = explode(",", $utilFragment[0]);

        foreach ($acceptList as $media) {
            $media =  Slugify::create()->slugify($media, ' ');
            $media = ucwords($media);
            $media = str_replace(" ", "", $media);
            $media[0] = strtolower($media[0]);
            $method = $media . "Response";
            $exist = method_exists($this, $method);

            if ($exist) {
                return $this->$method($data, $code, $headers, $options);
            }
        }

        return new Response("Unsupported Media Type", 415);

    }

    /**
     * Process Json Response in Rest API controller
     * 
     * @param array $data 
     * @param int $code 
     * @param array $headers 
     * @param array $options 
     * 
     * @return Symfony\Component\HttpFoundation\Response;
     */

    public function applicationJsonResponse($data, $code, $headers, $options)
    {

        $array = $data;

        // ReadyFlag warns that the content is ready to
        // move to the response , ie, no need to adapt.

        if ($options["isContent"]) {

            
            $formatter = new dataFormatter($this->app);

            if ($options["single"]) {
                 $map = $formatter->data($data);
                 $content = array("record" => $map);
            } else {
                $map = $formatter->dataList($options['contenttype'], $data);
                $content = array("records" => $map);
            }

            /*
            if ($options["single"]) {
                $content = array(
                    "values" => $data->values,
                    "relation" => $data->relation,
                    "user" => $data->user['id']
                    );
                unset($content['values']['templatefields']);
            } else {
                $json = new JSONAccess($this->app);
                $map = $json->json_list($options['contenttype'], $data);
                $content = $data; //array("records" => $map);
            }*/

            $array = $content;
        }

        $json = json_encode(
            $array,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT
        );

        $response = new Response($json, $code, $headers);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');

        if ($this->config["cors"]['enabled']) {
            $response->headers->set(
                'Access-Control-Allow-Origin',
                $this->config["cors"]["allow-origin"]
            );
        };

        return $response;

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

        return $this->response(
            $content,
            200,
            array("single" => true,
            "isContent" => true)
        );

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

    public function insertAction($content, $contenttypeslug, $oldStatus)
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

        // To check whether the status is allowed, we act as if a status
        // *transition* were requested.
        $content->setFromPost($requestAll, $contenttype);
        $newStatus = $content['status'];

        // Save the record, and return to the overview screen, or to the record (if we clicked 'save and continue')
        $status = $this->app['users']->isContentStatusTransitionAllowed($oldStatus, $newStatus, $contenttype['slug'], $id);

        if (!$status) {
            $error["message"] = Trans::__("Error processing the request");
            $this->abort($error, 500);
        }

        // Get the associate record change comment
        $comment = $request->request->get('changelog-comment');

        // Save the record
        $result = $this->app['storage']->saveContent($content, $comment);

        if (!$result) {
            $error["message"] = Trans::__("Error processing the request");
            $this->abort($error, 500);
        }

        $responseData = array('id' => $result);

        $location = $this->app['url_generator']->generate(
            'readcontent',
            array('contenttypeslug' => $contenttypeslug,
            'slug' => $result),
            true
        );

        $options = array("isContent" => false);

        // Defalt headers
        $headers = array();

        // Detecting whether the answer is " created "
        if ($oldStatus == "") {
            $headers = array('Location' => $location);
            $code = 201;
        } else {
            $code = 200;
        }

        return $this->response($responseData, $code, $options, $headers);

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

        //set defaults
        $content['datecreated'] = date('Y-m-d');
        $content['datepublish'] = date('Y-m-d');
        $content['status'] = "published";
        
        return $this->insertAction($content, $contenttypeslug, "");

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

        $content = $this->app['storage']->getContent(
            $contenttypeslug,
            array(
                'id' => $slug,
                'status' => '!undefined'
            )
        );

        $oldStatus = $content['status'];

        return $this->insertAction($content, $contenttypeslug, $oldStatus);

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

        return $this->response($content, 204);

    }

    public function corsResponse()
    {
        if ($this->config["cors"]['enabled']) {
            $response = new Response();
            $response->headers->set(
                'Access-Control-Allow-Origin',
                $this->config["cors"]["allow-origin"]
            );
            $response->headers->set(
                'Access-Control-Allow-Headers',
                $this->config["security"]["jwt"]["request_header_name"]
            );
            return $response;
        } else {
            return "";
        }
    }
}
