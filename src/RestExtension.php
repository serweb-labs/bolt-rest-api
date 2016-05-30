<?php

namespace Bolt\Extension\SerWeb\Rest;

use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Bolt\Extension\SerWeb\Rest\Controller\RestController;
use Bolt\Extension\SerWeb\Rest\Controller\AuthenticateController;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rest extension class.
 *
 * @author Luciano Rodríguez <info@serweb.com.ar>
 */
class RestExtension extends SimpleExtension
{
    /**
     * {@inheritdoc}
     *
     * Mount the RestController class
     *
     * To see specific bindings between route and controller method see 'connect()'
     * function in the ExampleController class.
     */
    
    protected function registerFrontendControllers()
    {
        $app = $this->getContainer();
        $config = $this->getConfig();
        $config["username"] = $this->authorize($config, $app);

        return [
            $config['endpoints']['rest']  => new RestController($config, $app),
            $config['endpoints']['authenticate'] => new AuthenticateController($config, $app),
        ];
    }

    /**
     * Handles GET requests on the /example/url route.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function authorize($config, $app)
    {
        foreach ($config['security']['mode'] as $provider) {
            $cl = "Bolt\Extension\SerWeb\Rest\SecurityProvider\\" . $provider . "SecurityProvider";
            $provider = new $cl($config, $app);
            $result = $provider->getAuthorization();

            if ($result['result'] === true) {
                return $result['data'];
            }

            return false;
        }
    }
}
