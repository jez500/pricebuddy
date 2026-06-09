<?php

namespace Tests\Unit\Services\Ai;

use App\Models\Store;
use App\Services\Ai\HealingContext;
use App\Services\Ai\HtmlSafety;
use App\Services\Ai\Tools\FetchPageHtmlTool;
use Tests\TestCase;

class FetchPageHtmlToolTest extends TestCase
{
    public function test_description_carries_the_shared_untrusted_html_rule(): void
    {
        $tool = new FetchPageHtmlTool(new HealingContext('https://example.com', new Store));

        $this->assertStringContainsString(HtmlSafety::UNTRUSTED_RULE, (string) $tool->description());
    }
}
