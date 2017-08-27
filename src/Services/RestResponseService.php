<?php

namespace Bolt\Extension\SerWeb\Rest\Services;

use Silex\Application;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Cocur\Slugify\Slugify;

/**
 * Rest Response Service Class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class RestResponseService
{
    /** @var array The extension's configuration parameters */
    private $config;
    private $app;

    /**
     * Initiate the controller with Bolt Application instance and extension config.
     *
     * @param array $config
     */
    public function __construct(Application $app, array $config)
    {
        $this->config = $config;
        $this->app = $app;
    }



    /**
     * Detect "Accept" head and proccess
     *
     * @param array $data
     * @param int $code
     * @param array $headers
     *
     * @return $this->$method|Symfony\Component\HttpFoundation\Response;
     */
    public function response($data, $code, $headers = array(), $envelope = false)
    {
        $default = 'application/json';
        $media = $this->app['request']->headers->get('Accept', $default);
        
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
                return $this->$method($data, $code, $headers, $envelope);
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
     *
     * @return Symfony\Component\HttpFoundation\Response;
     */
    public function applicationJsonResponse($data, $code, $headers = array(), $envelope = false)
    {
        if ($this->config["cors"]['enabled']) {
            $headers['Access-Control-Allow-Origin'] = $this->config["cors"]["allow-origin"];
            $headers['Access-Control-Expose-Headers'] = 'X-Pagination-Limit, X-Pagination-Page, X-Total-Count';
        };

        $headers['Content-Type'] = 'application/json; charset=UTF-8';

        $response = new Response("{}", $code, $headers);

        if ($envelope) {
            $array = array('headers' =>  $headers, 'response' => $data);
        } else {
            $array = $data;
        }

        $json = json_encode(
            $array,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT
        );

        $response->setContent($json);
        
        return $response;
    }
}
