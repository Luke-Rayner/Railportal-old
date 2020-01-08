<?php

namespace UserFrosting\Sprinkle\IntelliSense\ServicesProvider;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use UserFrosting\Sprinkle\IntelliSense\Twig\MyExtension;

/**
 * Registers services for the site sprinkle.
 *
 * @package IntelliSense
 * @author Luke Rayner/ElephantWiFi
 * @link http://www.elephantwifi.co.uk
 */
class ServicesProvider
{
    /**
     * Register UserFrosting's site services.
     *
     * @param ContainerInterface $container A DI container implementing ArrayAccess and container-interop.
     */
    public function register(ContainerInterface $container)
    {   
        /*
         * Extend the 'classMapper' service to register model classes.
         *
         * Mappings added: User, Group, Role, Permission, Activity, PasswordReset, Verification
         *
         * @return \UserFrosting\Sprinkle\Core\Util\ClassMapper
         */
        $container->extend('classMapper', function ($classMapper, $c) {
            $classMapper->setClassMapping('user', 'UserFrosting\Sprinkle\IntelliSense\Database\Models\ExtendedUser');
            $classMapper->setClassMapping('user_sprunje', 'UserFrosting\Sprinkle\IntelliSense\Sprunje\UserSprunje');
            return $classMapper;
        });

        /**
         * Extends the 'view' service with the SiteExtension for Twig.
         *
         * Adds global variables to Twig for my site Sprinkle.
         */
        $container->extend('view', function ($view, $c) {
            $twig = $view->getEnvironment();
            $extension = new MyExtension($c);
            $twig->addExtension($extension);

            return $view;
        });

        /**
         * Returns a callback that handles setting the `UF-Redirect` header after a successful login.
         *
         * Overrides the service definition in the account Sprinkle.
         *
         * @return callable
         */
        $container['redirect.onLogin'] = function ($c) {
            /**
             * This method is invoked when a user completes the login process.
             *
             * Returns a callback that handles setting the `UF-Redirect` header after a successful login.
             * @param  \Psr\Http\Message\ServerRequestInterface $request
             * @param  \Psr\Http\Message\ResponseInterface      $response
             * @param  array                                    $args
             * @return \Psr\Http\Message\ResponseInterface
             */
            return function (Request $request, Response $response, array $args) use ($c) {
                // Backwards compatibility for the deprecated determineRedirectOnLogin service
                if ($c->has('determineRedirectOnLogin')) {
                    $determineRedirectOnLogin = $c->determineRedirectOnLogin;

                    return $determineRedirectOnLogin($response)->withStatus(200);
                }

                /** @var \UserFrosting\Sprinkle\Account\Authorize\AuthorizationManager */
                $authorizer = $c->authorizer;

                $currentUser = $c->authenticator->user();

                if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
                    return $response->withHeader('UF-Redirect', $c->router->pathFor('geo-sense-drone_summary'));
                } 
                else if (!$authorizer->checkAccess($currentUser, 'uri_wifi_user')) {
                    return $response->withHeader('UF-Redirect', $c->router->pathFor('geo-sense-dashboard'));
                }
                else {
                    return $response->withHeader('UF-Redirect', $c->router->pathFor('wifi-user-dashboard'));
                }
            };
        };

        /**
         * Returns a callback that forwards to dashboard if user is already logged in.
         *
         * @return callable
         */
        $container['redirect.onAlreadyLoggedIn'] = function ($c) {
            /**
             * This method is invoked when a user attempts to perform certain public actions when they are already logged in.
             *
             * @todo Forward to user's landing page or last visited page
             * @param  \Psr\Http\Message\ServerRequestInterface $request
             * @param  \Psr\Http\Message\ResponseInterface      $response
             * @param  array                                    $args
             * @return \Psr\Http\Message\ResponseInterface
             */
            return function (Request $request, Response $response, array $args) use ($c) {
                // Authentication Handler
                $authorizer = $c->authorizer;

                $currentUser = $c->authenticator->user();

                if ($authorizer->checkAccess($currentUser, 'uri_site_admin')) {
                    $redirect = $c->router->pathFor('geo-sense-drone_summary');
                }
                else if (!$authorizer->checkAccess($currentUser, 'uri_wifi_user')) {
                    $redirect = $c->router->pathFor('geo-sense-dashboard');
                }
                else {
                    $redirect = $c->router->pathFor('wifi-user-dashboard');
                }
                
                return $response->withRedirect($redirect);
            };
        };
    }
}
