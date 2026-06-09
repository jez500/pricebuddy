<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\HealingContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TestRegexTool implements Tool
{
    public function __construct(protected HealingContext $context) {}

    public function description(): Stringable|string
    {
        return 'Test a regex against the fetched HTML and return the extracted value. Wrap the target in a '
            .'capture group (). Good for extracting values from JSON embedded in the page. '
            .'Returns JSON {matched, value, error}.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'regex' => $schema->string()->description('The regex pattern to test.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->context->validate('regex', (string) ($request['regex'] ?? '')));
    }
}
