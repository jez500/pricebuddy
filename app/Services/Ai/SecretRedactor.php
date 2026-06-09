<?php

namespace App\Services\Ai;

final class SecretRedactor
{
    /**
     * Remove the configured secret and common credential patterns from a message
     * so it is safe to log.
     */
    public static function redact(string $message, ?string $secret): string
    {
        if (filled($secret)) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        $message = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\bsk-[A-Za-z0-9._\-]{8,}/', '[redacted]', $message) ?? $message;

        return $message;
    }
}
