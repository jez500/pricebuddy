<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Pages\ManagePriceHistory;
use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ManagePriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->product = Product::factory()->for($this->user)
            ->addUrlWithPrices('https://history.example/item', [40, 50, 60])
            ->create(['notify_price' => null, 'notify_percent' => null]);
    }

    public function test_owner_sees_raw_records_and_can_delete_a_single_reading(): void
    {
        $rows = $this->product->prices()->get();
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($rows)
            ->callTableAction('delete', $rows->first())
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$rows->first()]);

        $this->assertSame(2, $this->product->prices()->count());
        $this->assertDatabaseHas('urls', ['id' => $rows->first()->url_id]);
    }

    public function test_bulk_confirmation_cannot_be_changed_by_a_later_selection(): void
    {
        $rows = $this->product->prices()->reorder('id')->get();
        $component = Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->mountTableBulkAction('delete', [$rows[0], $rows[1]])
            ->assertTableBulkActionMounted('delete');
        $new = $this->product->urls()->firstOrFail()->updatePrice(30);
        $component->set('selectedTableRecords', [$new->getKey()])
            ->callMountedTableBulkAction()
            ->assertHasNoTableBulkActionErrors();

        $this->assertDatabaseMissing('prices', ['id' => $rows[0]->id]);
        $this->assertDatabaseMissing('prices', ['id' => $rows[1]->id]);
        $this->assertDatabaseHas('prices', ['id' => $rows[2]->id]);
        $this->assertDatabaseHas('prices', ['id' => $new->getKey()]);
    }

    public function test_listing_and_price_filters_drive_bulk_selection(): void
    {
        $url = $this->product->urls()->firstOrFail();
        $rows = $url->prices()->reorder('id')->get();
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->set('filters.listing_id', $url->id)
            ->set('filters.max_price', 50)
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertCanSeeTableRecords([$rows[0], $rows[1]])
            ->assertCanNotSeeTableRecords([$rows[2]])
            ->callTableBulkAction('delete', [$rows[0], $rows[1]])
            ->assertHasNoTableBulkActionErrors();

        $this->assertSame(1, $this->product->prices()->count());
    }

    public function test_invalid_filters_have_inline_errors_and_keep_the_previous_query(): void
    {
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->set('filters.from', '2026-10-03')
            ->set('filters.until', '2026-10-01')
            ->call('applyFilters')
            ->assertHasErrors(['filters.until']);
        $this->assertSame(3, $this->product->prices()->count());
    }

    public function test_a_deleted_applied_listing_filter_can_be_cleared(): void
    {
        $listing = $this->product->urls()->firstOrFail();
        $remaining = Url::factory()->for($this->product)->withPrices([80])->create();
        $foreign = Product::factory()->for(User::factory())->addUrlWithPrices('https://foreign.example/item', [10])->create();
        $component = Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->set('filters.listing_id', $listing->id)
            ->set('filters.max_price', 70)
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertCanSeeTableRecords($listing->prices()->get());
        $listing->delete();

        $component->call('$refresh')
            ->assertSuccessful()
            ->assertSee('No matching price records')
            ->assertCanNotSeeTableRecords($foreign->prices()->get())
            ->call('clearFilters')
            ->assertSuccessful()
            ->assertHasNoErrors()
            ->assertSet('appliedFilters', [])
            ->assertCanSeeTableRecords($remaining->prices()->get())
            ->assertCanNotSeeTableRecords($foreign->prices()->get());
    }

    public function test_an_applied_listing_moved_to_another_product_can_be_cleared(): void
    {
        $listing = $this->product->urls()->firstOrFail();
        $remaining = Url::factory()->for($this->product)->withPrices([80])->create();
        $foreign = Product::factory()->for(User::factory())->addUrlWithPrices('https://foreign.example/item', [10])->create();
        $component = Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->set('filters.listing_id', $listing->id)
            ->set('filters.max_price', 70)
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertCanSeeTableRecords($listing->prices()->get());
        $listing->update(['product_id' => $foreign->id]);

        $component->call('$refresh')
            ->assertSuccessful()
            ->assertSee('No matching price records')
            ->assertCanNotSeeTableRecords($foreign->prices()->get())
            ->call('clearFilters')
            ->assertSuccessful()
            ->assertHasNoErrors()
            ->assertSet('appliedFilters', [])
            ->assertCanSeeTableRecords($remaining->prices()->get())
            ->assertCanNotSeeTableRecords($foreign->prices()->get());
    }

    public function test_raw_records_are_paginated_instead_of_loading_the_whole_history(): void
    {
        $url = $this->product->urls()->firstOrFail();
        Price::withoutEvents(fn () => Price::factory()->count(30)->for($url)->create(['store_id' => $url->store_id]));

        $component = Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id]);

        $this->assertSame(33, $component->instance()->getTableRecords()->total());
        $this->assertCount(25, $component->instance()->getTableRecords());
    }

    public function test_recorded_prices_retain_the_currency_minor_unit_precision(): void
    {
        $url = $this->product->urls()->firstOrFail();
        $url->store->update(['settings' => ['locale_settings' => ['locale' => 'en_US', 'currency' => 'KWD']]]);
        $url->updatePrice(0.125);

        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->assertSee('KWD')
            ->assertSee('0.125');
    }

    public function test_other_users_cannot_open_the_history_page(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(ProductResource::getUrl('history', ['record' => $this->product]))->assertForbidden();
    }

    public function test_confirmation_can_be_cancelled_without_deleting_anything(): void
    {
        $row = $this->product->prices()->firstOrFail();
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->mountTableAction('delete', $row)
            ->call('unmountTableAction');

        $this->assertSame(3, $this->product->prices()->count());
    }

    public function test_the_browser_cannot_replace_the_sealed_confirmation(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->mountTableAction('delete', $this->product->prices()->firstOrFail())
            ->set('pendingDeletion', 'a different selection');
    }

    public function test_an_invalid_new_selection_cannot_reuse_a_cancelled_confirmation(): void
    {
        $row = $this->product->prices()->firstOrFail();
        $foreign = Product::factory()->addUrlWithPrices('https://other.example/item', [15])->create();
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->mountTableAction('delete', $row)
            ->call('unmountTableAction')
            ->mountTableBulkAction('delete', [$foreign->prices()->firstOrFail()])
            ->assertTableBulkActionNotMounted('delete')
            ->assertSet('pendingDeletion', null)
            ->assertSet('pendingDeletionCount', 0);

        $this->assertSame(3, $this->product->prices()->count());
        $this->assertSame(1, $foreign->prices()->count());
    }

    public function test_empty_history_does_not_remove_the_tracking_url(): void
    {
        $rows = $this->product->prices()->get();
        Livewire::test(ManagePriceHistory::class, ['record' => $this->product->id])
            ->callTableBulkAction('delete', $rows)
            ->assertHasNoTableBulkActionErrors()
            ->assertSee('No matching price records');
        $this->assertSame(1, $this->product->urls()->count());
        $this->assertEquals(0, $this->product->fresh()->current_price);
    }
}
