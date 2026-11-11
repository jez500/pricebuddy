<?php

namespace Tests\Feature\Services\Oidc;

use App\Enums\Role;
use App\Models\User;
use App\Services\Oidc\OidcClaims;
use App\Services\Oidc\OidcRoleSync;
use ChrisReedIO\Socialment\Models\ConnectedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Optional mapping of an OIDC group to the Admin role (OIDC_ADMIN_GROUP).
 */
class OidcRoleSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('oidc.admin_group', 'pricebuddy_admins');
        config()->set('oidc.groups_claim', 'groups');
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function login(User $user, array $claims): void
    {
        resolve(OidcClaims::class)->set($claims);

        $account = new ConnectedAccount;
        $account->setRelation('user', $user);

        resolve(OidcRoleSync::class)($account);
    }

    public function test_member_of_the_admin_group_is_promoted(): void
    {
        $user = User::factory()->create();

        $this->login($user, ['groups' => ['family', 'pricebuddy_admins']]);

        $this->assertSame(Role::Admin, $user->fresh()->role);
    }

    public function test_user_removed_from_the_admin_group_is_demoted(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->admin()->create();

        $this->login($user, ['groups' => ['family']]);

        $this->assertSame(Role::User, $user->fresh()->role);
    }

    public function test_an_empty_groups_list_demotes(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->admin()->create();

        $this->login($user, ['groups' => []]);

        $this->assertSame(Role::User, $user->fresh()->role);
    }

    public function test_the_last_admin_is_never_demoted(): void
    {
        $user = User::factory()->admin()->create();

        $this->login($user, ['groups' => ['family']]);

        $this->assertSame(Role::Admin, $user->fresh()->role);
    }

    public function test_a_missing_groups_claim_leaves_roles_alone(): void
    {
        User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->login($admin, ['email' => 'a@example.com']);
        $this->login($user, ['email' => 'b@example.com']);

        $this->assertSame(Role::Admin, $admin->fresh()->role);
        $this->assertSame(Role::User, $user->fresh()->role);
    }

    public function test_nothing_changes_when_no_admin_group_is_configured(): void
    {
        config()->set('oidc.admin_group', '');
        User::factory()->admin()->create();
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->login($admin, ['groups' => []]);
        $this->login($user, ['groups' => ['pricebuddy_admins']]);

        $this->assertSame(Role::Admin, $admin->fresh()->role);
        $this->assertSame(Role::User, $user->fresh()->role);
    }

    public function test_group_names_are_matched_exactly(): void
    {
        $user = User::factory()->create();

        $this->login($user, ['groups' => ['PriceBuddy_Admins', 'pricebuddy_admins_old']]);

        $this->assertSame(Role::User, $user->fresh()->role);
    }

    public function test_groups_sent_as_a_delimited_string_are_supported(): void
    {
        $user = User::factory()->create();

        $this->login($user, ['groups' => 'family, pricebuddy_admins']);

        $this->assertSame(Role::Admin, $user->fresh()->role);
    }

    public function test_the_groups_claim_name_is_configurable(): void
    {
        config()->set('oidc.groups_claim', 'roles');
        $user = User::factory()->create();

        $this->login($user, ['groups' => ['pricebuddy_admins'], 'roles' => ['other']]);
        $this->assertSame(Role::User, $user->fresh()->role);

        $this->login($user, ['roles' => ['pricebuddy_admins']]);
        $this->assertSame(Role::Admin, $user->fresh()->role);
    }
}
