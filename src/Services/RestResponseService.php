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
    public function response($data, $code, $headers = array())
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
                return $this->$method($data, $code, $headers);
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
    public function applicationJsonResponse($data, $code, $headers = array())
    {
        $array = $data;

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
            $response->headers->set(
                'Access-Control-Expose-Headers',
                'X-Pagination-Limit, X-Pagination-Page, X-Total-Count'
            );

        };

        return $response;
    }

    public function applicationXMLResponse($data, $code, $headers = array()) {

        function to_xml(\SimpleXMLElement $object, array $data)
        {   
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    if (is_numeric($key)) {
                        $key = 'record';
                    }
                    $new_object = $object->addChild($key);
                    to_xml($new_object, $value);
                } else {
                    if (is_numeric($key)) {
                        $key = 'value';
                    }  
                    $object->addChild($key, $value);
                }   
            }   
        }   

        $xml = new \SimpleXMLElement('<?xml version="1.0"?><data></data>');
        to_xml($xml, $data);
        $result = $xml->asXML();
        $response = new Response($result);
        $response->headers->set('Content-Type', 'xml');
        return $response;
    }  
}
