<?php

use App\Rules\ContainsSearchTermPlaceholder;

it('passes validation when url contains :search_term placeholder', function () {
    $rule = new ContainsSearchTermPlaceholder;
    $failCalled = false;
    $fail = function () use (&$failCalled) {
        $failCalled = true;
    };

    $rule->validate('url', 'https://example.com/search?q=:search_term', $fail);

    expect($failCalled)->toBeFalse();
});

it('fails validation when url does not contain :search_term placeholder', function () {
    $rule = new ContainsSearchTermPlaceholder;
    $failCalled = false;
    $fail = function () use (&$failCalled) {
        $failCalled = true;
    };

    $rule->validate('url', 'https://example.com/search?q=test', $fail);

    expect($failCalled)->toBeTrue();
});

it('passes validation with :search_term in path', function () {
    $rule = new ContainsSearchTermPlaceholder;
    $failCalled = false;
    $fail = function () use (&$failCalled) {
        $failCalled = true;
    };

    $rule->validate('url', 'https://example.com/search/:search_term', $fail);

    expect($failCalled)->toBeFalse();
});

it('fails validation when url is empty', function () {
    $rule = new ContainsSearchTermPlaceholder;
    $failCalled = false;
    $fail = function () use (&$failCalled) {
        $failCalled = true;
    };

    $rule->validate('url', '', $fail);

    expect($failCalled)->toBeTrue();
});

it('provides correct error message', function () {
    $rule = new ContainsSearchTermPlaceholder;
    $errorMessage = '';
    $fail = function ($message) use (&$errorMessage) {
        $errorMessage = $message;
    };

    $rule->validate('url', 'https://example.com/search', $fail);

    expect($errorMessage)->toContain(':search_term');
});
