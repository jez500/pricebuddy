<?php

/*
 * Single sign-on via OpenID Connect (OIDC).
 *
 * Disabled unless OIDC_ENABLED=true. Works with any OIDC compliant issuer
 * (Pocket ID, Authentik, Keycloak, Authelia, Entra, Google, etc) that serves a
 * discovery document at {OIDC_ISSUER_URL}/.well-known/openid-configuration
 *
 * The redirect (callback) URI to register with the issuer is:
 *   {APP_URL}/login/oidc/callback
 */

$enabled = (bool) env('OIDC_ENABLED', false);
$adminGroup = (string) env('OIDC_ADMIN_GROUP', '');

// The groups scope is only requested when groups are used for roles.
$scopes = array_values(array_unique(preg_split(
    '/[\s,]+/',
    (string) (env('OIDC_SCOPES') ?: ('openid email profile'.($adminGroup !== '' ? ' groups' : ''))),
    -1,
    PREG_SPLIT_NO_EMPTY
)));

return [

    // Socialite driver name is "{driver_prefix}{connection}", i.e. "oidc".
    'driver_prefix' => '',

    'connections' => $enabled ? [
        'oidc' => [
            'provider' => App\Services\Oidc\OidcProvider::class,
            'base_url' => env('OIDC_ISSUER_URL'),
            'client_id' => env('OIDC_CLIENT_ID'),
            'client_secret' => env('OIDC_CLIENT_SECRET'),
            'redirect' => env('OIDC_REDIRECT_URI', rtrim((string) env('APP_URL'), '/').'/login/oidc/callback'),
            'scopes' => $scopes,
            // A user without an email can not be matched or created.
            'require_email' => true,
        ],
    ] : [],

    // PriceBuddy specific settings.
    'enabled' => $enabled,

    // Text shown on the login button.
    'label' => env('OIDC_LABEL', 'Single sign-on'),

    // Create a PriceBuddy user the first time someone signs in via OIDC.
    'auto_register' => (bool) env('OIDC_AUTO_REGISTER', true),

    // Let an OIDC login sign in as an existing PriceBuddy user with the same
    // email. Only enable if your issuer verifies email addresses, otherwise
    // anyone who can claim an email at the issuer can take over that account.
    'link_by_email' => (bool) env('OIDC_LINK_BY_EMAIL', true),

    // Optional. Members of this group in the issuer are Admins, everyone else is
    // a User, checked on every login. Leave empty to manage roles in PriceBuddy.
    'admin_group' => $adminGroup,

    // Claim in the token that lists the user's groups.
    'groups_claim' => env('OIDC_GROUPS_CLAIM', 'groups'),
];
