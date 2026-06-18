<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\ApiKeyResource\Pages\ApiKeyCreated;
use App\Filament\Resources\ApiKeyResource\Pages\CreateApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiKeyResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        User::query()->delete();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_create_form_has_no_user_field(): void
    {
        Livewire::test(CreateApiKey::class)
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('permission_mode')
            ->assertFormFieldDoesNotExist('tokenable_id');
    }

    public function test_all_mode_creates_a_wildcard_token_for_current_user(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm(['name' => 'CI key', 'permission_mode' => 'all'])
            ->call('create')
            ->assertHasNoFormErrors();

        $token = $this->user->tokens()->first();
        $this->assertNotNull($token);
        $this->assertSame((int) $this->user->id, (int) $token->tokenable_id);
        $this->assertSame(['*'], $token->abilities);
    }

    public function test_custom_mode_creates_a_token_with_the_selected_abilities(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => 'Scoped key',
                'permission_mode' => 'custom',
                'abilities' => ['custom' => ['meta-extraction:extract']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['meta-extraction:extract'], $this->user->tokens()->first()->abilities);
    }

    public function test_custom_mode_requires_at_least_one_ability(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm(['name' => 'Empty key', 'permission_mode' => 'custom'])
            ->call('create')
            ->assertHasFormErrors(['permission_mode']);

        $this->assertNull($this->user->tokens()->first());
    }

    public function test_custom_mode_rejects_a_smuggled_wildcard_ability(): void
    {
        // A '*' is not an offered checkbox; submitting it must not mint a wildcard token.
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => 'Sneaky key',
                'permission_mode' => 'custom',
                'abilities' => ['custom' => ['*']],
            ])
            ->call('create')
            ->assertHasFormErrors(['permission_mode']);

        $this->assertNull($this->user->tokens()->first());
    }

    public function test_custom_mode_drops_unknown_abilities_and_keeps_known_ones(): void
    {
        Livewire::test(CreateApiKey::class)
            ->fillForm([
                'name' => 'Mixed key',
                'permission_mode' => 'custom',
                'abilities' => ['custom' => ['meta-extraction:extract', '*', 'not-a-real-ability']],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['meta-extraction:extract'], $this->user->tokens()->first()->abilities);
    }

    public function test_created_page_shows_the_token_once_then_redirects(): void
    {
        session()->put('api-key.plain_text', 'plain-token-value');
        session()->put('api-key.name', 'CI key');

        Livewire::test(ApiKeyCreated::class)
            ->assertSet('plainTextToken', 'plain-token-value')
            ->assertSee('CI key');

        Livewire::test(ApiKeyCreated::class)
            ->assertRedirect(ApiKeyResource::getUrl('index'));
    }
}
