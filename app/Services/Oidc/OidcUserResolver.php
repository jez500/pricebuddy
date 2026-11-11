<?php

namespace App\Services\Oidc;

use App\Enums\Role;
use App\Models\User;
use ChrisReedIO\Socialment\Exceptions\AbortedLoginException;
use ChrisReedIO\Socialment\Models\ConnectedAccount;
use Illuminate\Support\Str;

/**
 * Decides which PriceBuddy user a first-time OIDC login belongs to.
 *
 * Returning logins are matched on (provider, subject) by Socialment and never
 * reach this class.
 */
class OidcUserResolver
{
    public function __invoke(ConnectedAccount $account): User
    {
        $email = $this->normalizeEmail($account->email);

        if ($email === null) {
            throw new AbortedLoginException(
                'Your single sign-on account has no email address, so it can not be used to log in.'
            );
        }

        $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($existing) {
            if (! config('oidc.link_by_email')) {
                throw new AbortedLoginException(
                    'A user with this email already exists and is not linked to single sign-on.'
                );
            }

            return $existing;
        }

        if (! config('oidc.auto_register')) {
            throw new AbortedLoginException(
                'No PriceBuddy user exists for this account and automatic registration is disabled.'
            );
        }

        // The password is never used, sign in happens via the issuer. A random
        // value stops the account being usable with an empty/known password.
        return User::create([
            'name' => $account->name ?: Str::before($email, '@'),
            'email' => $email,
            'password' => Str::random(64),
            'role' => Role::User,
        ]);
    }

    protected function normalizeEmail(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));

        return $email === '' ? null : $email;
    }
}
