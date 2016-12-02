<?php

namespace Bolt\Extension\SerWeb\Rest\Services;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CookieAuthenticationService class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class CookieAuthenticationService
{
    private $app;
    private $config;

    public function __construct(array $config, Application $app)
    {
        $this->app = $app;
        $this->config = $config;
    }


    public function autenticate()
    {
        return false;
    }
}
