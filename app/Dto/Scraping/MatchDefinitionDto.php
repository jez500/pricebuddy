<?php

namespace App\Dto\Scraping;

use JsonSerializable;

class MatchDefinitionDto implements JsonSerializable
{
    /**
     * @param  array<string, MatchRuleDto>  $rules
     */
    public function __construct(
        public mixed $default = null,
        public array $rules = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $rules = [];

        foreach ($data as $key => $value) {
            if ($key === 'default') {
                continue;
            }

            $rules[(string) $key] = $value instanceof MatchRuleDto
                ? $value
                : MatchRuleDto::fromArray(is_array($value) ? $value : ['value' => $value]);
        }

        return new self(
            default: data_get($data, 'default'),
            rules: $rules,
        );
    }

    public function toArray(): array
    {
        $output = [
            'default' => $this->default,
        ];

        foreach ($this->rules as $key => $rule) {
            $output[$key] = $rule->toArray();
        }

        return $output;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function resolve(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $this->default;
        }

        foreach ($this->rules as $output => $rule) {
            if ($rule->value === null || $rule->value === '') {
                continue;
            }

            if ($this->matches($value, $rule)) {
                return $output;
            }
        }

        return $this->default;
    }

    private function matches(string $value, MatchRuleDto $rule): bool
    {
        return match ($rule->type) {
            'regex' => $this->matchesRegex($value, $rule->value),
            default => trim($value) === trim((string) $rule->value),
        };
    }

    private function matchesRegex(string $value, ?string $pattern): bool
    {
        if ($pattern === null || $pattern === '') {
            return false;
        }

        $wrapped = '~'.$this->escapeRegexDelimiter($pattern).'~i';

        return (bool) @preg_match($wrapped, $value);
    }

    private function escapeRegexDelimiter(string $pattern, string $delimiter = '~'): string
    {
        $escaped = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character === $delimiter) {
                $backslashes = 0;

                for ($offset = $index - 1; $offset >= 0 && $pattern[$offset] === '\\'; $offset--) {
                    $backslashes++;
                }

                if ($backslashes % 2 === 0) {
                    $escaped .= '\\';
                }
            }

            $escaped .= $character;
        }

        return $escaped;
    }
}
