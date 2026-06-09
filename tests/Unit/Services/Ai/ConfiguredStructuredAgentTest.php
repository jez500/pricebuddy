<?php

use App\Services\Ai\ConfiguredStructuredAgent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

it('exposes injected generation options via methods', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'Extract data.',
        schema: fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
        temperature: 0.1,
        maxTokens: 1500,
    );

    expect($agent->instructions())->toBe('Extract data.')
        ->and($agent->temperature())->toBe(0.1)
        ->and($agent->maxTokens())->toBe(1500);
});

it('returns null for unset generation options so the provider default is used', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'Extract data.',
        schema: fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
    );

    expect($agent->temperature())->toBeNull()
        ->and($agent->maxTokens())->toBeNull()
        ->and($agent->topP())->toBeNull();
});

it('builds the schema array from the provided closure', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'Extract data.',
        schema: fn (JsonSchema $schema) => ['price' => $schema->number()->required()],
    );

    $built = $agent->schema(new JsonSchemaTypeFactory);

    expect($built)->toHaveKey('price');
});

it('exposes generation options including max steps', function () {
    $agent = new ConfiguredStructuredAgent(
        instructions: 'do thing',
        schema: fn ($s) => ['ok' => $s->boolean()],
        temperature: 0.3,
        maxTokens: 1234,
        maxSteps: 7,
    );

    expect($agent->temperature())->toBe(0.3)
        ->and($agent->maxTokens())->toBe(1234)
        ->and($agent->maxSteps())->toBe(7);
});
