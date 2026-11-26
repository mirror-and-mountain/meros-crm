<?php

return [
    'meros_crm_salesforce' => [
        'force_enabled'               => false,
        'site_environment'            => env('SALESFORCE_SITE_ENVIRONMENT', 'production'),
        'allow_environment_switching' => true,
        'environments'     => [
            'production'   => [
                'allow_configuration' => true,
                'base_url'            => env('SALESFORCE_PRODUCTION_BASE_URL', ''),
                'client_id'           => env('SALESFORCE_PRODUCTION_CLIENT_ID', ''),
                'client_secret'       => env('SALESFORCE_PRODUCTION_CLIENT_SECRET', ''),
            ],
            'sandbox'      => [
                'allow_configuration' => true,
                'base_url'            => env('SALESFORCE_SANDBOX_BASE_URL', ''),
                'client_id'           => env('SALESFORCE_SANDBOX_CLIENT_ID', ''),
                'client_secret'       => env('SALESFORCE_SANDBOX_CLIENT_SECRET', ''),
            ]
        ]
    ]
];