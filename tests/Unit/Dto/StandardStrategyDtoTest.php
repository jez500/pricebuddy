<?php

namespace Tests\Unit\Dto;

use App\Dto\StandardStrategyDto;
use App\Enums\ScraperStrategyType;
use PHPUnit\Framework\TestCase;

class StandardStrategyDtoTest extends TestCase
{
    public function test_from_array_builds_typed_dto(): void
    {
        $dto = StandardStrategyDto::fromArray([
            'type' => 'selector',
            'value' => '#p',
            'prepend' => '$',
            'append' => '0',
        ]);

        $this->assertSame(ScraperStrategyType::Selector, $dto->type);
        $this->assertSame('#p', $dto->value);
        $this->assertSame('$', $dto->prepend);
        $this->assertSame('0', $dto->append);
    }

    public function test_from_array_returns_null_for_missing_or_invalid_type(): void
    {
        $this->assertNull(StandardStrategyDto::fromArray(null));
        $this->assertNull(StandardStrategyDto::fromArray([]));
        $this->assertNull(StandardStrategyDto::fromArray(['type' => '']));
        $this->assertNull(StandardStrategyDto::fromArray(['type' => 'bogus', 'value' => 'x']));
    }

    public function test_schema_org_allows_null_value(): void
    {
        $dto = StandardStrategyDto::fromArray(['type' => 'schema_org']);

        $this->assertSame(ScraperStrategyType::SchemaOrg, $dto->type);
        $this->assertNull($dto->value);
    }

    public function test_blank_strings_normalize_to_null(): void
    {
        $dto = StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '', 'prepend' => '', 'append' => '']);

        $this->assertNull($dto->value);
        $this->assertNull($dto->prepend);
        $this->assertNull($dto->append);
    }

    public function test_to_array_emits_only_populated_keys(): void
    {
        $this->assertEquals(
            ['type' => 'selector', 'value' => '#p'],
            StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '#p'])->toArray(),
        );

        $this->assertEquals(
            ['type' => 'selector', 'value' => '#p', 'prepend' => '$', 'append' => '0'],
            StandardStrategyDto::fromArray(['type' => 'selector', 'value' => '#p', 'prepend' => '$', 'append' => '0'])->toArray(),
        );

        $this->assertEquals(
            ['type' => 'schema_org'],
            StandardStrategyDto::fromArray(['type' => 'schema_org'])->toArray(),
        );
    }
}
