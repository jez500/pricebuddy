<?php

namespace Tests\Feature\Livewire;

use App\Enums\Icons;
use App\Livewire\SearchLog;
use Livewire\Livewire;
use Tests\TestCase;

class SearchLogTest extends TestCase
{
    public function test_log_entries_render_with_their_status_colours()
    {
        // The last message becomes the collapsed-log header, so keep a neutral
        // entry last to ensure the coloured icons are rendered as list items.
        $messages = [
            ['message' => 'Using cache', 'data' => ['icon' => Icons::Database->value], 'timestamp' => now()->toDateTimeString()],
            ['message' => 'Price found', 'data' => ['icon' => Icons::Success->value], 'timestamp' => now()->toDateTimeString()],
            ['message' => 'No Price found', 'data' => ['icon' => Icons::Warning->value], 'timestamp' => now()->toDateTimeString()],
            ['message' => 'Filtering', 'data' => ['icon' => Icons::Search->value], 'timestamp' => now()->toDateTimeString()],
        ];

        $html = Livewire::test(SearchLog::class, ['messages' => $messages])->html();

        // Colour is applied via Filament's CSS colour variables (inline style),
        // because the admin panel CSS does not ship raw `text-{colour}-500`
        // utility classes.
        $this->assertStringContainsString('rgb(var(--success-500))', $html);
        $this->assertStringContainsString('rgb(var(--warning-500))', $html);

        // The cache (database) icon is coloured blue via the info variable.
        $this->assertStringContainsString('rgb(var(--info-500))', $html);
    }
}
