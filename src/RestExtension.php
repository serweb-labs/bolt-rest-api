<?php

namespace Bolt\Extension\SerWeb\Rest;

use Bolt\Controller\Zone;
use Bolt\Extension\SimpleExtension;
use Bolt\Extension\SerWeb\Rest\Controller\RestController;
use Bolt\Extension\SerWeb\Rest\Controller\AuthenticateController;
use Silex\ControllerCollection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Application;
use Silex\Provider\SerializerServiceProvider;
use Bolt\Storage\Query\ContentQueryParser;
use Bolt\Extension\SerWeb\Rest\Directives\RelatedDirective;
use Bolt\Extension\SerWeb\Rest\Directives\UnrelatedDirective;
use Bolt\Extension\SerWeb\Rest\Directives\PaginationDirective;
use Bolt\Extension\SerWeb\Rest\Directives\CountDirective;
use Bolt\Extension\SerWeb\Rest\Directives\FilterDirective;
use Bolt\Extension\SerWeb\Rest\Services\Vendors\JsonApi;

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

        return [
            $config['endpoints']['rest']  => new RestController($config, $app),
            $config['endpoints']['authenticate'] => new AuthenticateController($config, $app),
        ];
    }


    /**
     * {@inheritdoc}
     */
    protected function registerServices(Application $app)
    {
        $config = $this->getConfig();

        $app['query.parser'] = $app->share(
            $app->extend('query.parser', function (ContentQueryParser $parser) {
                return $parser;
            })
        );

        $app['query.parser']->addDirectiveHandler('pagination', new PaginationDirective());
        $app['query.parser']->addDirectiveHandler('related', new RelatedDirective());
        $app['query.parser']->addDirectiveHandler('unrelated', new UnrelatedDirective());
        $app['query.parser']->addDirectiveHandler('filter', new FilterDirective());
        $app['query.parser']->addDirectiveHandler('count', new CountDirective());

        foreach ($config['security']['providers'] as $provider) {
            $name = ucfirst($provider) . "AuthenticationService";
            $cl = "Bolt\Extension\SerWeb\Rest\Services\\" . $name;

            $app[$name] = $app->share(
                function ($app) use ($config, $cl) {
                    return new $cl($config, $app);
                }
            );
        }

        $app['rest'] = $app->share(
            function ($app) use ($config) {
                $id = 'Bolt\Extension\SerWeb\Rest\Services\IdentifyService';
                return new $id($app, $config);
            }
        );

        $app['rest.response'] = $app->share(
            function ($app) use ($config) {
                $service = 'Bolt\Extension\SerWeb\Rest\Services\RestResponseService';
                return new $service($app, $config);
            }
        );

        /* vendors */
        $app['rest.jsonApi'] = $app->share(
            function ($app) use ($config) {
                return new JsonApi($app, $config);
            }
        );


        $app->register(new SerializerServiceProvider());
    }
}
