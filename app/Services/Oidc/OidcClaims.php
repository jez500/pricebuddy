<?php

namespace App\Services\Oidc;

use Illuminate\Support\Arr;

/**
 * Holds the claims from the current request's OIDC login, so they can be used
 * after Socialment has resolved the user (Socialment only passes the account).
 */
class OidcClaims
{
    /** @var array<mixed> */
    protected array $claims = [];

    /**
     * @param  array<mixed>  $claims
     */
    public function set(array $claims): void
    {
        $this->claims = $claims;
    }

    public function has(string $claim): bool
    {
        return Arr::has($this->claims, $claim) && Arr::get($this->claims, $claim) !== null;
    }

    /**
     * Values of a claim as a list of strings. Providers send groups either as
     * an array or as a delimited string.
     *
     * @return list<string>
     */
    public function list(string $claim): array
    {
        $value = Arr::get($this->claims, $claim);

        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_filter(
            Arr::wrap($value),
            fn (mixed $item): bool => is_string($item) && $item !== ''
        ));
    }
}
