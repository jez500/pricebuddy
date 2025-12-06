<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\TagResource;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TagResourceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_render_tag_index_page()
    {
        $this->actingAs($this->user);
        $this->get(TagResource::getUrl('index'))->assertSuccessful();
    }

    public function test_can_render_create_tag_page()
    {
        $this->actingAs($this->user);
        $this->get(TagResource::getUrl('create'))->assertSuccessful();
    }

    public function test_can_create_tag()
    {
        $this->actingAs($this->user);

        $newData = [
            'name' => 'Electronics',
            'weight' => 10,
        ];

        Livewire::test(TagResource\Pages\CreateTag::class)
            ->fillForm($newData)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tags', [
            'name' => 'Electronics',
            'weight' => 10,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_can_create_tag_with_default_weight()
    {
        $this->actingAs($this->user);

        $newData = [
            'name' => 'Books',
        ];

        Livewire::test(TagResource\Pages\CreateTag::class)
            ->fillForm($newData)
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('tags', [
            'name' => 'Books',
            'weight' => 0,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_validates_required_name()
    {
        $this->actingAs($this->user);

        Livewire::test(TagResource\Pages\CreateTag::class)
            ->fillForm([
                'name' => '',
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_can_edit_tag()
    {
        $this->actingAs($this->user);
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $params = ['record' => $tag->getRouteKey()];

        Livewire::test(TagResource\Pages\EditTag::class, $params)
            ->fillForm([
                'name' => 'Updated Name',
                'weight' => 20,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Updated Name', $tag->refresh()->name);
        $this->assertSame(20, $tag->weight);
    }

    public function test_can_delete_tag()
    {
        $this->actingAs($this->user);
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $params = ['record' => $tag->getRouteKey()];

        Livewire::test(TagResource\Pages\EditTag::class, $params)
            ->callAction('delete');

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_can_search_tags_by_name()
    {
        $this->actingAs($this->user);
        $tag1 = Tag::factory()->create([
            'name' => 'Electronics',
            'user_id' => $this->user->id,
        ]);
        $tag2 = Tag::factory()->create([
            'name' => 'Books',
            'user_id' => $this->user->id,
        ]);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->searchTable('Electronics')
            ->assertCanSeeTableRecords([$tag1])
            ->assertCanNotSeeTableRecords([$tag2]);
    }

    public function test_can_sort_by_name()
    {
        $this->actingAs($this->user);
        $tagA = Tag::factory()->create([
            'name' => 'A Tag',
            'user_id' => $this->user->id,
        ]);
        $tagZ = Tag::factory()->create([
            'name' => 'Z Tag',
            'user_id' => $this->user->id,
        ]);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->sortTable('name')
            ->assertCanSeeTableRecords([$tagA, $tagZ], inOrder: true)
            ->sortTable('name', 'desc')
            ->assertCanSeeTableRecords([$tagZ, $tagA], inOrder: true);
    }

    public function test_can_sort_by_weight()
    {
        $this->actingAs($this->user);
        $tag1 = Tag::factory()->create([
            'name' => 'Tag 1',
            'weight' => 10,
            'user_id' => $this->user->id,
        ]);
        $tag2 = Tag::factory()->create([
            'name' => 'Tag 2',
            'weight' => 5,
            'user_id' => $this->user->id,
        ]);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->sortTable('weight')
            ->assertCanSeeTableRecords([$tag2, $tag1], inOrder: true);
    }

    public function test_can_bulk_delete_tags()
    {
        $this->actingAs($this->user);
        $tags = Tag::factory()->count(3)->create(['user_id' => $this->user->id]);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->callTableBulkAction('delete', $tags);

        foreach ($tags as $tag) {
            $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        }
    }

    public function test_displays_table_columns_correctly()
    {
        $this->actingAs($this->user);
        $tag = Tag::factory()->create([
            'name' => 'Test Tag',
            'weight' => 5,
            'user_id' => $this->user->id,
        ]);

        // Create a product and attach the tag to test products_count
        $product = Product::factory()->create([
            'user_id' => $this->user->id,
        ]);
        $product->tags()->attach($tag);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->assertCanSeeTableRecords([$tag])
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('products_count')
            ->assertTableColumnExists('weight');
    }

    public function test_default_sort_is_by_weight()
    {
        $this->actingAs($this->user);
        $tag1 = Tag::factory()->create([
            'name' => 'High Priority',
            'weight' => 1,
            'user_id' => $this->user->id,
        ]);
        $tag2 = Tag::factory()->create([
            'name' => 'Low Priority',
            'weight' => 10,
            'user_id' => $this->user->id,
        ]);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->assertCanSeeTableRecords([$tag1, $tag2], inOrder: true);
    }

    public function test_only_shows_current_user_tags()
    {
        $this->actingAs($this->user);
        $userTag = Tag::factory()->create(['user_id' => $this->user->id]);
        $otherUserTag = Tag::factory()->create(['user_id' => User::factory()->create()->id]);

        Livewire::test(TagResource\Pages\ListTags::class)
            ->assertCanSeeTableRecords([$userTag])
            ->assertCanNotSeeTableRecords([$otherUserTag]);
    }
}
