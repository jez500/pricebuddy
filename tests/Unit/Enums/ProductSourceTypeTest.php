<?php

use App\Enums\ProductSourceType;

it('has correct cases', function () {
    expect(ProductSourceType::cases())->toHaveCount(2);
    expect(ProductSourceType::DealsSite)->toBeInstanceOf(ProductSourceType::class);
    expect(ProductSourceType::OnlineStore)->toBeInstanceOf(ProductSourceType::class);
});

it('has correct values', function () {
    expect(ProductSourceType::DealsSite->value)->toBe('deals_site');
    expect(ProductSourceType::OnlineStore->value)->toBe('online_store');
});

it('returns correct labels', function () {
    expect(ProductSourceType::DealsSite->getLabel())->toBe('Deals Site (Aggregator)');
    expect(ProductSourceType::OnlineStore->getLabel())->toBe('Online Store');
});

it('returns correct descriptions', function () {
    expect(ProductSourceType::DealsSite->getDescription())->toBe('Site that aggregates deals/links (e.g. OzBargain)');
    expect(ProductSourceType::OnlineStore->getDescription())->toBe('Site that sells products directly (e.g. Amazon)');
});
