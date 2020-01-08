<?php

/**
 * Sample site configuration file for UserFrosting.  You should definitely set these values!
 */
return [
    'site' => [
        'login' => [
            'enable_email' => true, // Set to false to allow login by username only
        ],
        'author' => [
            'Luke Rayner/ElephantWiFi'
        ],
        'site_title' => 'Intelli Sense',
        'uri' => [
            'author'  => 'https://elephantwifi.co.uk',
            'public'  => 'https://portal.intelli-sense.co.uk'
        ],
        'title' => 'ElephantWiFi',
        'debug' => [
            'ajax' => false,
            'info' => false,
        ]
    ],
    
    'php' => [
        'timezone' => 'Europe/London'
    ],

    'locale' => 'en_US',

    'password_reset' => [
        'algorithm' => 'sha512',
        'timeouts' => [
            'create' => 86400,
            'reset' => 10800
        ]
    ],

    /*
     * Use router cache, disable full error details
     */
    // 'settings' => [
    //     'routerCacheFile' => 'routes.cache',
    //     'displayErrorDetails' => false,
    // ],

    'address_book' => [
        'admin' => 'info@elephantwifi.co.uk'
    ],

    /*
     * Send errors to log
     */
    'php' => [
        'display_errors' => 'false',
        'log_errors' => 'true',
    ],
];