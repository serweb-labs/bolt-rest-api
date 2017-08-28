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
use Bolt\Extension\SerWeb\Rest\Directives\RelatedDirective;

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
    private $vendor;
    private $params;

    /**
     * Initiate the controller with Bolt Application instance and extension config.
     *
     * @param array $config
     */

    public function __construct(array $config, Application $app)
    {
        $this->app = $app;
        $this->config = $this->makeConfig($config);
    }

    /**
     * Specify which method handles which route.
     * @TODO: need support related content (JSONAPI SPEC)
     *
     * @param Application $app An Application instance
     *
     * @return ControllerCollection A ControllerCollection instance
     */

    public function connect(Application $app)
    {

        /** @var $ctr \Silex\ControllerCollection */
        $ctr = $this->app['controllers_factory'];

        $ctr->get('/{contenttype}', array($this, 'listContent'))
            ->value('action', 'view')
            ->bind('rest.listContent')
            ->before(array($this, 'before'));

        $ctr->get('/{contenttype}/{slug}', array($this, 'getContent'))
            ->value('action', 'view')
            ->bind('rest.getContent')
            ->before(array($this, 'before'));

        $ctr->post('/{contenttype}', array($this, 'createContentAction'))
            ->value('action', 'create')
            ->bind('rest.createcontent')
            ->before(array($this, 'before'));

        $ctr->patch('/{contenttype}/{slug}', array($this, 'updateContentAction'))
            ->value('action', 'edit')
            ->bind('rest.updatecontent')
            ->before(array($this, 'before'));

        $ctr->delete('/{contenttype}/{slug}', array($this, 'deleteContentAction'))
            ->value('action', 'delete')
            ->bind('rest.deletecontent')
            ->before(array($this, 'before'));

        $ctr->options('/{contenttype}', array($this, 'corsResponse'))
            ->bind('rest.listContent.options');

        $ctr->options('/{contenttype}/{slug}', array($this, 'corsResponse'))
            ->bind('rest.getContent.options');

        return $ctr;
    }
 
    private function getDefaultSort()
    {
        return $this->config["sort"]["default"] ? : "-id";
    }

    // @TODO: contenttype specif
    private function getDefaultLimit()
    {
        return ($this->app['config']->get('general/listing_records'));
    }

    // @TODO: in the future maybe slug only change
    // on create content
    private function makeConfig($config)
    {
        $default = array(
            "filter-fields-available" => ["status"],
            "read-only-fields" => array(
                'datechanged',
                'datedepublish',
                'datepublish',
                'datecreated',
                'ownerid',
                'id',
            ),
            "soft-delete" => array(
                "enabled" => false,
            ),
            "default-query" => array(
                "status" => "published",
                "related" => false,
                "unrelated" => false,
                "contain" => false,
                "deep" => false,
            ),
            "default-options" => array(
                "count" => true,
                "limit" => $this->getDefaultLimit(),
                "page" => 1,
                "sort" => $this->getDefaultSort(),
            ),
            "thumbnail" => array(
                "width" => 500,
                "height" => 500,
            )
        );
        return ($config) ? array_merge((array) $default, (array) $config) : $default;
    }


    private function checkPermission($request)
    {
        $contenttype = $request->get("contenttype");
        $slug = $request->get("slug") ? ":" . $request->get("slug") : "";
        $action = $request->get("action");
        $what = "contenttype:{$contenttype}:{$action}{$slug}";
        return $this->app['permissions']->isAllowed($what, $this->user);
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
        // Get and set User
        $user = $this->app['users']->getUser($this->app['rest']->getUsername());
        $this->user = $user;

        // Check permissions
        $allow = $this->checkPermission($request);
        if (!$allow) {
            $error = Trans::__("You don't have the correct permissions");
            return $this->abort($error, Response::HTTP_FORBIDDEN);
        }

        // @TODO: temporal

        // Get expected content type
        $mime = $request->headers->get('Content-Type');

        $this->vendor = "jsonApi"; //$this->app['rest.vendors']->getVendor($mime);

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

    public function listContent(Request $request)
    {
        return $this->app["rest.{$this->vendor}"]->listContent($request, $this->config);
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