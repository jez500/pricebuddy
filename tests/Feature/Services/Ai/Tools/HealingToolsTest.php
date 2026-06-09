<?php

namespace Tests\Feature\Services\Ai\Tools;

use App\Models\Store;
use App\Services\Ai\HealingContext;
use App\Services\Ai\Tools\TestCssSelectorTool;
use App\Services\Ai\Tools\TestRegexTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class HealingToolsTest extends TestCase
{
    use RefreshDatabase;

    private function context(string $html): HealingContext
    {
        return new HealingContext('https://shop.test/x', Store::factory()->create(['settings' => []]), $html);
    }

    public function test_css_tool_returns_matched_value(): void
    {
        $tool = new TestCssSelectorTool($this->context('<html><body><span id="p">$3.50</span></body></html>'));

        $result = json_decode((string) $tool->handle(new Request(['selector' => '#p'])), true);

        $this->assertTrue($result['matched']);
        $this->assertSame('$3.50', $result['value']);
    }

    public function test_regex_tool_returns_matched_value(): void
    {
        $tool = new TestRegexTool($this->context('<html><body><script>{"price":7.25}</script></body></html>'));

        $result = json_decode((string) $tool->handle(new Request(['regex' => '"price":\s*([0-9.]+)'])), true);

        $this->assertTrue($result['matched']);
        $this->assertSame('7.25', $result['value']);
    }

    public function test_tools_expose_description_and_schema(): void
    {
        $tool = new TestCssSelectorTool($this->context('<html></html>'));
        $schema = new JsonSchemaTypeFactory;

        $this->assertNotEmpty((string) $tool->description());
        $this->assertArrayHasKey('selector', $tool->schema($schema));
    }
}
