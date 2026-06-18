<?php

namespace Tests\Unit\Enums;

use App\Enums\ApiAbility;
use PHPUnit\Framework\TestCase;

class ApiAbilityTest extends TestCase
{
    public function test_has_the_custom_route_abilities(): void
    {
        $this->assertSame('meta-extraction:extract', ApiAbility::MetaExtractionExtract->value);
        $this->assertSame('user:detail', ApiAbility::UserDetail->value);
    }

    public function test_each_case_has_a_label_and_group(): void
    {
        foreach (ApiAbility::cases() as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame('', $case->group());
        }

        $this->assertSame('Meta extraction', ApiAbility::MetaExtractionExtract->group());
        $this->assertSame('Account', ApiAbility::UserDetail->group());
    }
}
