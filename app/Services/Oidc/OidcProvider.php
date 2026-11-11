<?php

namespace App\Services\Oidc;

use SocialiteProviders\OpenIDConnect\Provider;
use Throwable;

/**
 * Generic OIDC provider that also records the login's claims in OidcClaims.
 */
class OidcProvider extends Provider
{
    public function user()
    {
        $user = parent::user();

        /** @var array<mixed> $claims */
        $claims = (array) $user->getRaw();
        $groupsClaim = config()->string('oidc.groups_claim');

        // Some issuers only put groups in the userinfo response.
        if (config('oidc.admin_group') && ! array_key_exists($groupsClaim, $claims) && $user->token) {
            try {
                $claims = array_merge((array) $this->getUserByToken($user->token), $claims);
            } catch (Throwable) {
                // Without groups the role is left unchanged, see OidcRoleSync.
            }
        }

        resolve(OidcClaims::class)->set($claims);

        return $user;
    }
}
