<?php

/*
 * UserFrosting (http://www.userfrosting.com)
 *
 * @link      https://github.com/userfrosting/UserFrosting
 * @copyright Copyright (c) 2019 Alexander Weissman
 * @license   https://github.com/userfrosting/UserFrosting/blob/master/LICENSE.md (MIT License)
 */

namespace UserFrosting\Sprinkle\IntelliSense\Twig;

use Psr\Container\ContainerInterface;

/**
 * Extends Twig functionality for the IntelliSense sprinkle.
 *
 * @author Luke Rayner/ElephantWiFi
 */
class MyExtension extends \Twig_Extension implements \Twig_Extension_GlobalsInterface
{
    /**
     * @var ContainerInterface
     */
    protected $services;

    /**
     * @param ContainerInterface $services
     */
    public function __construct(ContainerInterface $services)
    {
        $this->services = $services;
    }

    /**
     * Get the name of this extension.
     *
     * @return string
     */
    public function getName()
    {
        return 'userfrosting/intelli-sense';
    }

    public function getGlobals()
    {
        try {
            $currentUser = $this->services->currentUser;
        } catch (\Exception $e) {
            $currentUser = null;
        }

        // 'session_expiry_time' => $currentUser->session_expiry_time ?: 55,
        return [
            'current_user' => $currentUser,
            'session_expiry_time' => 30 ?: 55,
            'test_variable' => 'hello',
        ];
    }
}
