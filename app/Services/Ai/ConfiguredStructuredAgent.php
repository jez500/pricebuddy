<?php

namespace App\Services\Ai;

use Closure;
use Laravel\Ai\StructuredAnonymousAgent;

/**
 * A structured anonymous agent that carries generation options (temperature,
 * max tokens, top-p) sourced from application settings. The Laravel AI SDK
 * resolves these via method_exists() in TextGenerationOptions::forAgent(),
 * so defining them as methods is how anonymous-style agents set generation options.
 */
class ConfiguredStructuredAgent extends StructuredAnonymousAgent
{
    /**
     * The first four parameters mirror the parent constructor's order and types so the
     * signature stays compatible with StructuredAnonymousAgent; the generation options
     * are appended after. Construct with named arguments, e.g.
     * `new ConfiguredStructuredAgent(instructions: ..., schema: ..., temperature: ...)`.
     */
    public function __construct(
        string $instructions,
        iterable $messages = [],
        iterable $tools = [],
        ?Closure $schema = null,
        protected ?float $temperature = null,
        protected ?int $maxTokens = null,
        protected ?float $topP = null,
    ) {
        parent::__construct($instructions, $messages, $tools, $schema);
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    public function maxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function topP(): ?float
    {
        return $this->topP;
    }
}
