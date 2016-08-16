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
class nativeSecurityProvider
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

        return false;

    }
}
