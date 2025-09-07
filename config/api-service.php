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
        'use_resource_middlewares' => true,
    ],
    'tenancy' => [
        'enabled' => false,
        'awareness' => false,
    ],
    'login-rules' => [
        'email' => 'required|email',
        'password' => 'required|string',
    ],
    'login-middleware' => [
        'throttle:api',
    ],
    'logout-middleware' => [
        'auth:sanctum',
    ],
    'use-spatie-permission-middleware' => false,
];
