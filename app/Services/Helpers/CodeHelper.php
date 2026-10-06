<?php

namespace App\Services\Helpers;

use Phiki\Grammar\Grammar;
use Phiki\Phiki;
use Phiki\Theme\Theme;

class CodeHelper
{
    /**
     * Phiki takes about 1 second for 10 KB of HTML, and much longer for long lines
     * (6.5 seconds for one 10 KB line). A full page can exceed the PHP time limit (#224).
     */
    public const int MAX_HIGHLIGHT_LENGTH = 10_000;

    public const int MAX_HIGHLIGHT_LINE_LENGTH = 500;

    public static function formatHtml(string $code): string
    {
        if (! self::canHighlight($code)) {
            return self::plainHtml($code);
        }

        $phiki = new Phiki;

        return (string) $phiki->codeToHtml($code, Grammar::Html, Theme::GithubDark);
    }

    public static function canHighlight(string $code): bool
    {
        if (strlen($code) > self::MAX_HIGHLIGHT_LENGTH) {
            return false;
        }

        foreach (explode("\n", $code) as $line) {
            if (strlen($line) > self::MAX_HIGHLIGHT_LINE_LENGTH) {
                return false;
            }
        }

        return true;
    }

    /**
     * The same markup and colours as the Phiki output, without the syntax colours.
     */
    protected static function plainHtml(string $code): string
    {
        return '<pre class="phiki language-html github-dark" data-language="html" style="background-color: #24292e;color: #e1e4e8;white-space: pre-wrap;word-break: break-all;"><code>'
            .e($code)
            .'</code></pre>';
    }
}
