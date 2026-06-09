<?php

namespace App\Dto;

use App\Enums\AiProvider;

class AiProviderConfigDto
{
    public function __construct(
        public string $id,
        public string $name,
        public AiProvider $type,
        public ?string $model = null,
        public ?string $baseUrl = null,
        public ?string $apiKey = null,
        public int $timeoutSeconds = 60,
        public int $maxTokens = 2000,
        public float $temperature = 0.2,
    ) {}

    /**
     * Build a DTO from a stored provider array, or null if it is not usable.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $type = AiProvider::tryFrom((string) ($data['type'] ?? ''));

        if ($type === null || blank($data['id'] ?? null)) {
            return null;
        }

        return new self(
            id: (string) $data['id'],
            name: filled($data['name'] ?? null) ? (string) $data['name'] : $type->name,
            type: $type,
            model: $data['model'] ?? null,
            baseUrl: $data['base_url'] ?? null,
            apiKey: $data['api_key'] ?? null,
            timeoutSeconds: (int) ($data['timeout_seconds'] ?? 60),
            maxTokens: (int) ($data['max_tokens'] ?? 2000),
            temperature: (float) ($data['temperature'] ?? 0.2),
        );
    }
}
