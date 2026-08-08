<?php

namespace App\Dto;

use App\Enums\HealingReason;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * What AI healing did during one meta-extraction request. Mutable and threaded through
 * the extraction so every path — store-backed and auto-create — reports an outcome,
 * including the paths that never reach healing at all.
 *
 * @implements Arrayable<string, mixed>
 */
class HealingOutcomeDto implements Arrayable, JsonSerializable
{
    public function __construct(
        public bool $attempted = false,
        public bool $applied = false,
        public ?HealingReason $reason = null,
    ) {}

    /**
     * Healing was never started. Also used when it could not be started because too
     * little budget remained.
     */
    public function skipped(HealingReason $reason): void
    {
        $this->attempted = false;
        $this->applied = false;
        $this->reason = $reason;
    }

    public function started(): void
    {
        $this->attempted = true;
        $this->applied = false;
        $this->reason = null;
    }

    public function succeeded(): void
    {
        $this->attempted = true;
        $this->applied = true;
        $this->reason = null;
    }

    public function failed(HealingReason $reason): void
    {
        $this->attempted = true;
        $this->applied = false;
        $this->reason = $reason;
    }

    /**
     * @return array{attempted: bool, applied: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'attempted' => $this->attempted,
            'applied' => $this->applied,
            'reason' => $this->reason?->value,
        ];
    }

    /**
     * @return array{attempted: bool, applied: bool, reason: string|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
