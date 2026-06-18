<?php

namespace Tests\Unit\Enums;

use App\Enums\AvailabilityMatchType;
use PHPUnit\Framework\TestCase;

class AvailabilityMatchTypeTest extends TestCase
{
    public function test_has_match_and_regex_cases(): void
    {
        $this->assertSame('match', AvailabilityMatchType::Match->value);
        $this->assertSame('regex', AvailabilityMatchType::Regex->value);
        $this->assertSame(AvailabilityMatchType::Regex, AvailabilityMatchType::tryFrom('regex'));
        $this->assertNull(AvailabilityMatchType::tryFrom('nope'));
    }
}
