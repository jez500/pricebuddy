<?php

use App\Models\User;

// Config for chrisreedio/socialment, used for OIDC single sign-on.
// Providers are registered in AdminPanelProvider, see also config/oidc.php
return [
    'providers' => [],

    'view' => [
        'prompt' => 'Or log in with',
        'providers-list' => 'socialment::providers-list',
    ],

    'models' => [
        'user' => User::class,
    ],
];
