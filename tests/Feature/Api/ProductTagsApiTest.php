<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ProductTagsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $token = $this->user->createToken('test-token')->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);

        $this->product = Product::factory()->create(['user_id' => $this->user->id]);
    }

    private function includedTagIds(): array
    {
        return collect($this->getJson("/api/products/{$this->product->id}?include=tags")->json('data.tags'))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
    }

    public function test_can_sync_tags_on_a_product(): void
    {
        $tags = Tag::factory()->count(2)->create(['user_id' => $this->user->id]);

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => $tags->pluck('id')->all()])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame($tags->pluck('id')->sort()->values()->all(), $this->includedTagIds());
    }

    public function test_sync_replaces_the_existing_tag_set(): void
    {
        [$old, $new] = Tag::factory()->count(2)->create(['user_id' => $this->user->id]);
        $this->product->tags()->attach($old);

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => [$new->id]])
            ->assertOk();

        $this->assertSame([$new->id], $this->includedTagIds());
    }

    public function test_sync_with_an_empty_list_clears_all_tags(): void
    {
        $this->product->tags()->attach(Tag::factory()->count(2)->create(['user_id' => $this->user->id]));

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => []])
            ->assertOk();

        $this->assertSame([], $this->includedTagIds());
    }

    public function test_sync_does_not_require_title_or_image(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);
        $title = $this->product->title;

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => [$tag->id]])
            ->assertOk();

        $this->assertSame($title, $this->product->fresh()->title);
    }

    public function test_sync_rejects_an_unknown_tag_id(): void
    {
        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => [999999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags.0');
    }

    public function test_sync_rejects_another_users_tag(): void
    {
        $foreign = Tag::factory()->create();

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags.0');

        $this->assertSame([], $this->includedTagIds());
    }

    public function test_sync_requires_the_tags_key(): void
    {
        $this->putJson("/api/products/{$this->product->id}/tags", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags');
    }

    public function test_cannot_sync_tags_on_another_users_product(): void
    {
        $other = Product::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        $this->putJson("/api/products/{$other->id}/tags", ['tags' => [$tag->id]])
            ->assertNotFound();

        $this->assertSame(0, $other->tags()->count());
    }

    public function test_can_detach_a_tag_from_a_product(): void
    {
        [$keep, $remove] = Tag::factory()->count(2)->create(['user_id' => $this->user->id]);
        $this->product->tags()->attach([$keep->id, $remove->id]);

        $this->deleteJson("/api/products/{$this->product->id}/tags/{$remove->id}")
            ->assertNoContent();

        $this->assertSame([$keep->id], $this->includedTagIds());
    }

    public function test_cannot_detach_a_tag_from_another_users_product(): void
    {
        $other = Product::factory()->create();
        $tag = Tag::factory()->create(['user_id' => $other->user_id]);
        $other->tags()->attach($tag);

        $this->deleteJson("/api/products/{$other->id}/tags/{$tag->id}")
            ->assertNotFound();

        $this->assertSame(1, $other->tags()->count());
    }

    public function test_tag_routes_require_the_product_update_ability(): void
    {
        $tag = Tag::factory()->create(['user_id' => $this->user->id]);

        Auth::forgetGuards();
        $detailOnly = $this->user->createToken('detail-only', ['product:detail'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$detailOnly]);

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => [$tag->id]])->assertForbidden();
        $this->deleteJson("/api/products/{$this->product->id}/tags/{$tag->id}")->assertForbidden();

        Auth::forgetGuards();
        $update = $this->user->createToken('update', ['product:update'])->plainTextToken;
        $this->withHeaders(['Authorization' => 'Bearer '.$update]);

        $this->putJson("/api/products/{$this->product->id}/tags", ['tags' => [$tag->id]])->assertOk();
        $this->deleteJson("/api/products/{$this->product->id}/tags/{$tag->id}")->assertNoContent();
    }
}
