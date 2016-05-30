<?php

namespace Bolt\Extension\SerWeb\Rest\Controller;

use Silex\Application;
use Silex\ControllerCollection;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Cocur\Slugify\Slugify;
use Bolt\Translation\Translator as Trans;
use Bolt\Events\AccessControlEvent;
use \Firebase\JWT\JWT;

/**
 * Authenticate Controller class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class AuthenticateController implements ControllerProviderInterface
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

        $ctr->post('/login', array($this, 'login'))
            ->bind('login');

        $ctr->options('/login', array($this, 'corsLogin'))
            ->bind('corsLogin');

        return $ctr;

    }

    /**
     * Handle a login attempt.
     *
     * @param \Silex\Application $app The application/container
     * @param Request $request The Symfony Request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function login(Request $request)
    {
        $username = trim($request->get('username'));
        $password = trim($request->get('password'));
        $cfgsec = $this->config["security"];
        $key = $cfgsec["jwt"]["secret"];
        $event = new AccessControlEvent($request);

        try {
  
            if (empty($username) || empty($password)) {
                throw new \Exception('Username does not exist.');
            }

            if (!$this->app['access_control.login']->login($username, $password, $event)) {
                throw new \Exception('Login Fail.');
            } else {
                $time = time();

                $data = array(
                    'iat' => $time,
                    'exp' => $time + ($cfgsec["jwt"]["lifetime"]),
                    'data' => [
                        'id' => $username,
                        ]
                );

                $jwt = JWT::encode($data, $key);
                $token = $cfgsec["jwt"]["prefix"] . " " . $jwt;
                
                $response = new Response();
                $response->headers->set('X-Access-Token', $token);

                $response->headers->set(
                    'Access-Control-Allow-Origin',
                    $this->config["cors"]["allow-origin"]
                );

                $response->headers->set('Access-Control-Allow-Credentials', 'true');

                $response->headers->set(
                    'Access-Control-Expose-Headers',
                    $cfgsec["response-header-name"]
                );

                return $response;
            }
        } catch (\Exception $e) {
            return new Response(Trans::__("fail"), 401);
        }
    }

    /**
     * Handle a login attempt.
     *
     * @param Request $request The Symfony Request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */

    public function corsLogin()
    {
        $response = new Response();
        $cfgsec = $this->config["security"];

        if ($this->config["cors"]["enabled"]) {
            $response->headers->set('Access-Control-Allow-Methods', 'POST');

            $response->headers->set(
                'Access-Control-Allow-Origin',
                $this->config["cors"]["allow-origin"]
            );

            $response->headers->set(
                'Access-Control-Allow-Headers',
                $cfgsec["response-header-name"] . ", content-type"
            );

            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }
        $response->headers->set('Allow', 'POST');

        return $response;
    }
}
