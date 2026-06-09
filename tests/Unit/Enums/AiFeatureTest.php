<?php

namespace Tests\Unit\Enums;

use App\Enums\AiFeature;
use PHPUnit\Framework\TestCase;

class AiFeatureTest extends TestCase
{
    public function test_cases_have_string_values(): void
    {
        $this->assertSame('extraction', AiFeature::Extraction->value);
        $this->assertSame('healing', AiFeature::Healing->value);
    }

    public function test_each_case_has_a_human_label(): void
    {
        $this->assertSame('Extraction', AiFeature::Extraction->label());
        $this->assertSame('Healing', AiFeature::Healing->label());
    }
}
