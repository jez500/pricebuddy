<?php

namespace Tests\Unit\Rules;

use App\Rules\ContainsSearchTermPlaceholder;
use PHPUnit\Framework\TestCase;

class ContainsSearchTermPlaceholderTest extends TestCase
{
    public function test_passes_validation_when_url_contains_search_term_placeholder(): void
    {
        $rule = new ContainsSearchTermPlaceholder;
        $failCalled = false;
        $fail = function () use (&$failCalled) {
            $failCalled = true;
        };

        $rule->validate('url', 'https://example.com/search?q=:search_term', $fail);

        $this->assertFalse($failCalled);
    }

    public function test_fails_validation_when_url_does_not_contain_search_term_placeholder(): void
    {
        $rule = new ContainsSearchTermPlaceholder;
        $failCalled = false;
        $fail = function () use (&$failCalled) {
            $failCalled = true;
        };

        $rule->validate('url', 'https://example.com/search?q=test', $fail);

        $this->assertTrue($failCalled);
    }

    public function test_passes_validation_with_search_term_in_path(): void
    {
        $rule = new ContainsSearchTermPlaceholder;
        $failCalled = false;
        $fail = function () use (&$failCalled) {
            $failCalled = true;
        };

        $rule->validate('url', 'https://example.com/search/:search_term', $fail);

        $this->assertFalse($failCalled);
    }

    public function test_fails_validation_when_url_is_empty(): void
    {
        $rule = new ContainsSearchTermPlaceholder;
        $failCalled = false;
        $fail = function () use (&$failCalled) {
            $failCalled = true;
        };

        $rule->validate('url', '', $fail);

        $this->assertTrue($failCalled);
    }

    public function test_provides_correct_error_message(): void
    {
        $rule = new ContainsSearchTermPlaceholder;
        $errorMessage = '';
        $fail = function ($message) use (&$errorMessage) {
            $errorMessage = $message;
        };

        $rule->validate('url', 'https://example.com/search', $fail);

        $this->assertStringContainsString(':search_term', $errorMessage);
    }
}
