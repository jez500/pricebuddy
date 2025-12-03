<?php

use App\Enums\ProductSourceStatus;

it('has correct cases', function () {
    expect(ProductSourceStatus::cases())->toHaveCount(3);
    expect(ProductSourceStatus::Active)->toBeInstanceOf(ProductSourceStatus::class);
    expect(ProductSourceStatus::Inactive)->toBeInstanceOf(ProductSourceStatus::class);
    expect(ProductSourceStatus::Draft)->toBeInstanceOf(ProductSourceStatus::class);
});

it('has correct values', function () {
    expect(ProductSourceStatus::Active->value)->toBe('active');
    expect(ProductSourceStatus::Inactive->value)->toBe('inactive');
    expect(ProductSourceStatus::Draft->value)->toBe('draft');
});

it('returns correct labels', function () {
    expect(ProductSourceStatus::Active->getLabel())->toBe('Active');
    expect(ProductSourceStatus::Inactive->getLabel())->toBe('Inactive');
    expect(ProductSourceStatus::Draft->getLabel())->toBe('Draft');
});

it('returns correct colors', function () {
    expect(ProductSourceStatus::Active->getColor())->toBe('success');
    expect(ProductSourceStatus::Inactive->getColor())->toBe('danger');
    expect(ProductSourceStatus::Draft->getColor())->toBe('gray');
});
