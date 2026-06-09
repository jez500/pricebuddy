<?php

namespace App\Services\Ai\Tools;

use App\Services\Ai\HealingContext;
use App\Services\Ai\HtmlSafety;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class FetchPageHtmlTool implements Tool
{
    public function __construct(protected HealingContext $context) {}

    public function description(): Stringable|string
    {
        return 'Fetch the product page HTML. Use rendered=false for fast static HTML (try this first); '
            .'use rendered=true for browser-rendered HTML on JavaScript-heavy sites. Returns the page HTML '
            .'(may be truncated). '.HtmlSafety::UNTRUSTED_RULE;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'rendered' => $schema->boolean()->description('Return browser-rendered HTML (slower). Defaults to false.'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->context->fetch((bool) ($request['rendered'] ?? false));
    }
}
