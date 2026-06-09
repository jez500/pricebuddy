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
