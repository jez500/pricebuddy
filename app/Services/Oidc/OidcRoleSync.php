<?php

namespace App\Services\Oidc;

use App\Enums\Role;
use App\Models\User;
use ChrisReedIO\Socialment\Models\ConnectedAccount;
use Illuminate\Support\Facades\Log;

/**
 * Optionally keeps a user's role in step with their OIDC group membership.
 *
 * With OIDC_ADMIN_GROUP set, members of that group are Admins and everyone else
 * is a User, re-evaluated on every login. With it unset roles are managed in
 * PriceBuddy as normal.
 */
class OidcRoleSync
{
    public function __construct(protected OidcClaims $claims) {}

    public function __invoke(ConnectedAccount $account): void
    {
        $adminGroup = trim(config()->string('oidc.admin_group'));
        $user = $account->user;

        if ($adminGroup === '' || ! $user instanceof User) {
            return;
        }

        $groupsClaim = config()->string('oidc.groups_claim');

        // A missing claim usually means the scope was not granted. Treating it as
        // "in no groups" would demote every admin, so leave roles alone instead.
        if (! $this->claims->has($groupsClaim)) {
            Log::warning("OIDC login for user {$user->id} has no '{$groupsClaim}' claim, role left unchanged.");

            return;
        }

        $role = in_array($adminGroup, $this->claims->list($groupsClaim), true) ? Role::Admin : Role::User;

        if ($user->role === $role) {
            return;
        }

        if ($role === Role::User && $this->isLastAdmin($user)) {
            Log::warning(
                "OIDC login for user {$user->id} is not in '{$adminGroup}' but is the only Admin, role left unchanged."
            );

            return;
        }

        $user->update(['role' => $role]);
    }

    protected function isLastAdmin(User $user): bool
    {
        return $user->role === Role::Admin
            && ! User::query()->where('role', Role::Admin)->whereKeyNot($user->getKey())->exists();
    }
}
