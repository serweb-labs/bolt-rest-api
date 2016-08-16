<?php

namespace Bolt\Extension\SerWeb\Rest\SecurityProvider;

use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Bolt\Extension\SerWeb\Rest\Controller\RestController;
use Bolt\Extension\SerWeb\Rest\Controller\AuthenticateController;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;

/**
 * Rest extension class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class JwtSecurityProvider
{
    private $app;
    private $config;


    /**
     * {@inheritdoc}
     *
     * Mount the RestController class
     *
     * To see specific bindings between route and controller method see 'connect()'
     * function in the ExampleController class.
     */

    public function __construct(array $config, $app)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /**
     * Handles GET requests on the /example/url route.
     *
     * @param Request $request
     *
     * @return array
     */
    public function getAuthorization()
    {
        $cfg = $this->config['security']['jwt'];

        $time = time();

        // GET Token
        $jwt = $this->app['request']->headers->get($cfg['request_header_name']);

        if (strpos($jwt, $cfg['prefix'] . " ") !== false) {
            $final = str_replace($cfg['prefix'] . " ", "", $jwt);
        }

        // Validate Token
        try {
            $data = JWT::decode($final, $cfg['secret'], array($cfg['algoritm']));
            $result = array('result' => true, 'data' => $data->data->id);
        } catch (\Exception $e) {
            return $result = array('result' => false);
        }

        return $result;

    }
}
