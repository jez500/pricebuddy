<?php

namespace Tests\Unit\Dto;

use App\Dto\AvailabilityMatchDto;
use App\Enums\AvailabilityMatchType;
use PHPUnit\Framework\TestCase;

class AvailabilityMatchDtoTest extends TestCase
{
    public function test_from_array_builds_dto(): void
    {
        $dto = AvailabilityMatchDto::fromArray(['type' => 'regex', 'value' => 'sold.?out']);

        $this->assertSame(AvailabilityMatchType::Regex, $dto->type);
        $this->assertSame('sold.?out', $dto->value);
    }

    public function test_from_array_defaults_type_to_match(): void
    {
        $dto = AvailabilityMatchDto::fromArray(['value' => 'Sold out']);

        $this->assertSame(AvailabilityMatchType::Match, $dto->type);
    }

    public function test_from_array_returns_null_when_value_blank(): void
    {
        $this->assertNull(AvailabilityMatchDto::fromArray(null));
        $this->assertNull(AvailabilityMatchDto::fromArray(['type' => 'match', 'value' => '']));
        $this->assertNull(AvailabilityMatchDto::fromArray(['type' => 'match']));
    }

    public function test_to_array_round_trips(): void
    {
        $this->assertEquals(
            ['type' => 'match', 'value' => 'Sold out'],
            AvailabilityMatchDto::fromArray(['type' => 'match', 'value' => 'Sold out'])->toArray(),
        );
    }
}
