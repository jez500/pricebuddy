<?php

return [
    'navigation' => [],
    'models' => [
        'token' => [
            'enable_policy' => false,
        ],
    ],
    'route' => [
        'panel_prefix' => false,
        'use_resource_middlewares' => false,
    ],
    'tenancy' => [
        'enabled' => false,
        'awareness' => false,
    ],
    'login-rules' => [
        'email' => 'required|email',
        'password' => 'required',
    ],
    'login-middleware' => [
        // Add any additional middleware you want to apply to the login route
    ],
    'logout-middleware' => [
        'auth:sanctum',
        // Add any additional middleware you want to apply to the logout route
    ],
    'use-spatie-permission-middleware' => false,
];
