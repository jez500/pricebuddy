<?php

namespace Tests\Unit\Services;

use App\Services\Helpers\CodeHelper;
use Tests\TestCase;

class CodeHelperTest extends TestCase
{
    public function test_small_html_is_highlighted(): void
    {
        $html = CodeHelper::formatHtml('<b class="price">$10</b>');

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('<span class="token"', $html);
    }

    public function test_large_html_is_escaped_without_highlighting(): void
    {
        $row = '<div class="item"><a href="/p/1">Product</a></div>'."\n";
        $code = str_repeat($row, intdiv(CodeHelper::MAX_HIGHLIGHT_LENGTH, strlen($row)) + 1);

        $start = microtime(true);
        $html = CodeHelper::formatHtml($code);

        $this->assertLessThan(1, microtime(true) - $start);
        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringNotContainsString('<span class="token"', $html);
        $this->assertStringContainsString('&lt;div class=&quot;item&quot;&gt;', $html);
        $this->assertStringNotContainsString('<div class="item">', $html);
    }

    public function test_long_line_is_not_highlighted(): void
    {
        $code = str_repeat('<i>x</i>', intdiv(CodeHelper::MAX_HIGHLIGHT_LINE_LENGTH, 8) + 1);

        $this->assertFalse(CodeHelper::canHighlight($code));
        $this->assertTrue(CodeHelper::canHighlight(str_replace('>', ">\n", $code)));
    }
}
