<?php

namespace App\Services\Helpers;

use ValueError;

/**
 * Convert scraped HTML to UTF-8. Raw ISO-8859-1 bytes cannot be stored in a
 * utf8mb4 column, so the database cache rejected them (#203).
 */
class HtmlEncodingHelper
{
    /**
     * Browsers decode ISO-8859-1 and ASCII as Windows-1252, which is a superset.
     */
    protected const array ENCODING_ALIASES = [
        'iso-8859-1' => 'Windows-1252',
        'latin1' => 'Windows-1252',
        'us-ascii' => 'Windows-1252',
    ];

    public static function toUtf8(string $html, ?string $contentType = null): string
    {
        $charset = self::charsetFromContentType($contentType) ?? self::charsetFromMeta($html);

        if ($charset === null || in_array($charset, ['utf-8', 'utf8'])) {
            if (mb_check_encoding($html, 'UTF-8')) {
                return $html;
            }

            // Declared UTF-8 with some invalid bytes: drop only the invalid bytes.
            // No declared charset: Windows-1252 is the most common legacy encoding.
            if ($charset !== null) {
                return mb_scrub($html, 'UTF-8');
            }

            $charset = 'windows-1252';
        }

        try {
            $converted = mb_convert_encoding($html, 'UTF-8', self::ENCODING_ALIASES[$charset] ?? $charset);
        } catch (ValueError) {
            // mbstring does not know the charset.
            return mb_scrub($html, 'UTF-8');
        }

        // The DOM parser reads the meta charset. Without this change it would decode the
        // UTF-8 bytes a second time as the old charset.
        return (string) preg_replace(
            '/(<meta\b[^>]*?charset\s*=\s*["\']?)[\w.:-]+/i',
            '${1}utf-8',
            $converted
        );
    }

    public static function charsetFromContentType(?string $contentType): ?string
    {
        if ($contentType && preg_match('/charset\s*=\s*["\']?([\w.:-]+)/i', $contentType, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    public static function charsetFromMeta(string $html): ?string
    {
        // The HTML specification requires the declaration in the first 1024 bytes.
        // Some pages put it later, so read a larger part.
        if (preg_match('/<meta\b[^>]*?charset\s*=\s*["\']?([\w.:-]+)/i', substr($html, 0, 4096), $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
