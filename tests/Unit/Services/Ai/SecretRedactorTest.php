<?php

use App\Services\Ai\SecretRedactor;

it('redacts the supplied secret', function () {
    $out = SecretRedactor::redact('failed with key my-secret-123 in request', 'my-secret-123');

    expect($out)->not->toContain('my-secret-123')->toContain('[redacted]');
});

it('redacts every occurrence of the supplied secret', function () {
    $out = SecretRedactor::redact('topsecret appears topsecret twice', 'topsecret');

    expect($out)->not->toContain('topsecret')
        ->and(substr_count($out, '[redacted]'))->toBe(2);
});

it('redacts bearer tokens even without a supplied secret', function () {
    $out = SecretRedactor::redact('Authorization: Bearer abc.def-123', null);

    expect($out)->not->toContain('abc.def-123')->toContain('Bearer [redacted]');
});

it('redacts sk- style keys of 8+ chars', function () {
    $out = SecretRedactor::redact('used sk-ABCDEFGH12345678 here', null);

    expect($out)->not->toContain('sk-ABCDEFGH12345678')->toContain('[redacted]');
});

it('leaves short sk- fragments untouched', function () {
    expect(SecretRedactor::redact('value sk-123 only', null))->toBe('value sk-123 only');
});

it('leaves unrelated text intact', function () {
    expect(SecretRedactor::redact('cURL error 28: Connection timed out', null))
        ->toBe('cURL error 28: Connection timed out');
});
