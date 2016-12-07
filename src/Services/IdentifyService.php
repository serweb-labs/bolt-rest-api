<?php

namespace Bolt\Extension\SerWeb\Rest\Services;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Application;

/**
 * Rest extension class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class IdentifyService
{
    private $app;
    private $config;
    public $endpoint;

    public function __construct(Application $app, $config)
    {
        $this->app = $app;
        $this->config = $config;
        $this->endpoint = $config['endpoints']['rest'];
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function getUsername()
    {
        foreach ($this->config['security']['providers'] as $provider) {
            $name = ucfirst($provider) . "AuthenticationService";
            $result = $this->app[$name]->autenticate();
            if ($result['result'] === true) {
                return $result['data'];
            }
        }
        return 'Anonymous';
    }
}
