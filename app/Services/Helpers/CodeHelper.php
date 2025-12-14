<?php

namespace App\Services\Helpers;

use Phiki\Grammar\Grammar;
use Phiki\Phiki;
use Phiki\Theme\Theme;

class CodeHelper
{
    public static function formatHtml(string $code): string
    {
        $phiki = new Phiki;

        return $phiki->codeToHtml($code, Grammar::Html, Theme::GithubDark);
    }
}
