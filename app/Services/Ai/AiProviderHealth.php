<?php

namespace App\Services\Ai;

use App\Dto\AiProviderConfigDto;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A circuit breaker for AI providers.
 *
 * A wedged provider fails the same way for every caller, and each caller pays the full
 * timeout to find out. After enough consecutive failures the breaker opens and callers
 * that cannot afford to wait — the synchronous meta-extraction endpoint — skip the
 * provider outright instead of paying again.
 *
 * Callers who can afford to wait (the admin UI, queued jobs) deliberately do NOT consult
 * the breaker. They keep exercising the provider, so a recovery is noticed and the
 * breaker closes on the first success rather than only when the cooldown lapses.
 */
class AiProviderHealth
{
    public static function new(): self
    {
        return resolve(static::class);
    }

    /**
     * Whether the provider has failed often enough recently that a caller on a deadline
     * should not spend its budget finding out again.
     */
    public function isUnavailable(AiProviderConfigDto $provider): bool
    {
        return Cache::get($this->openKey($provider)) !== null;
    }

    /**
     * Consecutive failures are what matter: a provider that fails, succeeds, then fails
     * is slow or flaky, not wedged, so the counter resets on every success.
     */
    public function recordFailure(AiProviderConfigDto $provider): void
    {
        $cooldown = $this->cooldownSeconds();
        $threshold = $this->failureThreshold();

        // The window is the cooldown: failures spread more thinly than that are not a
        // wedged provider and should never accumulate into an open breaker.
        $failures = (int) Cache::get($this->failureKey($provider), 0) + 1;
        Cache::put($this->failureKey($provider), $failures, $cooldown);

        if ($failures < $threshold) {
            return;
        }

        Cache::put($this->openKey($provider), true, $cooldown);

        Log::warning('AI provider circuit breaker opened.', [
            'provider' => $provider->name,
            'failures' => $failures,
            'cooldown_seconds' => $cooldown,
        ]);
    }

    public function recordSuccess(AiProviderConfigDto $provider): void
    {
        Cache::forget($this->failureKey($provider));
        Cache::forget($this->openKey($provider));
    }

    public function failureThreshold(): int
    {
        return max(1, (int) config('price_buddy.ai_provider_breaker.failure_threshold', 3));
    }

    public function cooldownSeconds(): int
    {
        return max(1, (int) config('price_buddy.ai_provider_breaker.cooldown_seconds', 300));
    }

    protected function failureKey(AiProviderConfigDto $provider): string
    {
        return 'ai-provider-health:failures:'.$provider->id;
    }

    protected function openKey(AiProviderConfigDto $provider): string
    {
        return 'ai-provider-health:open:'.$provider->id;
    }
}
