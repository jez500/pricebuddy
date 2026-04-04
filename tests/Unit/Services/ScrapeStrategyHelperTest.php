<?php

use App\Dto\Scraping\FieldExtractionDto;
use App\Dto\Scraping\ScrapeSchemaDto;
use App\Services\Helpers\ScrapeStrategyHelper;

it('builds a ScrapeSchemaDto from a valid strategy', function () {
    $strategy = [
        'title' => ['type' => 'selector', 'value' => 'meta[property=og:title]|content'],
        'price' => ['type' => 'selector', 'value' => '.price'],
    ];

    $schema = ScrapeStrategyHelper::toSchema($strategy);

    expect($schema)->toBeInstanceOf(ScrapeSchemaDto::class)
        ->and($schema->fields)->toHaveCount(2)
        ->and($schema->fields['title'])->toBeInstanceOf(FieldExtractionDto::class)
        ->and($schema->fields['title']->type)->toBe('css')
        ->and($schema->fields['price']->type)->toBe('css');
});

it('excludes schema_org fields from schema', function () {
    $strategy = [
        'title' => ['type' => 'schema_org', 'value' => null],
        'price' => ['type' => 'selector', 'value' => '.price'],
    ];

    $schema = ScrapeStrategyHelper::toSchema($strategy);

    expect($schema->fields)->toHaveCount(1)
        ->and($schema->fields)->toHaveKey('price')
        ->and($schema->fields)->not->toHaveKey('title');
});

it('strips match keys from fields', function () {
    $strategy = [
        'availability' => [
            'type' => 'regex',
            'value' => '~"availability":"https?://schema.org/(\w+)"~',
            'match' => [
                'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                'default' => 'in_stock',
            ],
        ],
    ];

    $schema = ScrapeStrategyHelper::toSchema($strategy);

    expect($schema->fields['availability'])->toBeInstanceOf(FieldExtractionDto::class)
        ->and($schema->fields['availability']->match)->toBeNull();
});

it('normalizes selector type to css', function () {
    $fields = [
        'title' => ['type' => 'selector', 'value' => 'h1'],
        'price' => ['type' => 'regex', 'value' => '~price:(\d+)~'],
    ];

    $normalized = ScrapeStrategyHelper::normalizeForExtraction($fields);

    expect($normalized['title']['type'])->toBe('css')
        ->and($normalized['price']['type'])->toBe('regex');
});

it('strips match keys during normalization', function () {
    $fields = [
        'availability' => [
            'type' => 'selector',
            'value' => '.stock',
            'match' => ['default' => 'in_stock'],
        ],
    ];

    $normalized = ScrapeStrategyHelper::normalizeForExtraction($fields);

    expect($normalized['availability'])->not->toHaveKey('match');
});

it('handles empty strategy gracefully', function () {
    $schema = ScrapeStrategyHelper::toSchema([]);

    expect($schema)->toBeInstanceOf(ScrapeSchemaDto::class)
        ->and($schema->fields)->toBeEmpty();
});

it('handles invalid data gracefully without throwing', function () {
    $strategy = [
        'title' => ['type' => 'invalid_type', 'value' => 'test'],
    ];

    $schema = ScrapeStrategyHelper::toSchema($strategy);

    expect($schema)->toBeInstanceOf(ScrapeSchemaDto::class)
        ->and($schema->fields)->toBeEmpty();
});

it('returns availability match config', function () {
    $strategy = [
        'availability' => [
            'type' => 'regex',
            'value' => '~test~',
            'match' => [
                'out_of_stock' => ['type' => 'match', 'value' => 'OutOfStock'],
                'default' => 'in_stock',
            ],
        ],
    ];

    $match = ScrapeStrategyHelper::getAvailabilityMatch($strategy);

    expect($match)->toBeArray()
        ->and($match['out_of_stock'])->toBe(['type' => 'match', 'value' => 'OutOfStock'])
        ->and($match['default'])->toBe('in_stock');
});

it('returns null when no match config defined', function () {
    $strategy = [
        'title' => ['type' => 'selector', 'value' => 'h1'],
    ];

    expect(ScrapeStrategyHelper::getAvailabilityMatch($strategy))->toBeNull();
});

it('returns null when availability has no match key', function () {
    $strategy = [
        'availability' => ['type' => 'selector', 'value' => '.stock'],
    ];

    expect(ScrapeStrategyHelper::getAvailabilityMatch($strategy))->toBeNull();
});

it('validates a valid strategy and returns true', function () {
    $strategy = [
        'title' => ['type' => 'selector', 'value' => 'h1'],
        'price' => ['type' => 'regex', 'value' => '~(\d+\.\d{2})~'],
    ];

    expect(ScrapeStrategyHelper::validate($strategy))->toBeTrue();
});

it('validates an invalid strategy and returns errors', function () {
    $strategy = [
        'title' => ['type' => 'invalid_type', 'value' => 'h1'],
    ];

    $result = ScrapeStrategyHelper::validate($strategy);

    expect($result)->toBeArray()
        ->and($result)->not->toBeEmpty();
});

it('validates empty strategy as true', function () {
    expect(ScrapeStrategyHelper::validate([]))->toBeTrue();
});

it('preserves prepend and append in schema', function () {
    $strategy = [
        'price' => [
            'type' => 'selector',
            'value' => '.price',
            'prepend' => '$',
            'append' => ' AUD',
        ],
    ];

    $schema = ScrapeStrategyHelper::toSchema($strategy);

    expect($schema->fields['price']->prepend)->toBe('$')
        ->and($schema->fields['price']->append)->toBe(' AUD');
});

it('skips non-array field definitions', function () {
    $strategy = [
        'title' => ['type' => 'selector', 'value' => 'h1'],
        'invalid' => 'not-an-array',
        'empty' => null,
    ];

    $schema = ScrapeStrategyHelper::toSchema($strategy);

    expect($schema->fields)->toHaveCount(1)
        ->and($schema->fields)->toHaveKey('title');
});
