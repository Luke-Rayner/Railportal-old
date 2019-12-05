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
            'author'  => 'https://kipla.co.uk',
            'public'  => 'https://devportal.intelli-sense.co.uk'
        ]
    ],
    
    'php' => [
        'timezone' => 'Europe/London'
    ],

    'locale' => 'en_US',

    'password_reset' => [
        'algorithm'  => 'sha512',
        'timeouts'   => [
            'create' => 86400,
            'reset'  => 10800
        ]
    ],

    'address_book' => [
        'admin' => 'support@elephantwifi.co.uk'
    ]
];