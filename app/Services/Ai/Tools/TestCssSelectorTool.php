<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\HealingContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TestCssSelectorTool implements Tool
{
    public function __construct(protected HealingContext $context) {}

    public function description(): Stringable|string
    {
        return 'Test a CSS selector against the fetched HTML and return the extracted value. '
            .'Append |attribute_name to read an attribute instead of the element text '
            .'(e.g. "meta[property=og:price:amount]|content"). Returns JSON {matched, value, error}.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'selector' => $schema->string()->description('The CSS selector to test.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->context->validate('selector', (string) ($request['selector'] ?? '')));
    }
}
