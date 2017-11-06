<?php

namespace Bolt\Extension\SerWeb\Rest\Services;

use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Firebase\JWT\JWT;

/**
 * JwtAuthenticationService class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class JwtAuthenticationService
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
