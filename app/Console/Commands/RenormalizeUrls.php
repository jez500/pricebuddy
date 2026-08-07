<?php

namespace App\Console\Commands;

use App\Models\Url;
use Illuminate\Console\Command;

class RenormalizeUrls extends Command
{
    const COMMAND = 'urls:renormalize';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND;

    /**
     * The console command description.
     */
    protected $description = 'Rebuild the normalised match key for every tracked URL. Run this after changing the tracking parameter denylist in config/url_matching.php.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $changed = Url::renormalizeAll();

        $this->components->info(sprintf('Re-normalised %d URL(s).', $changed));

        return self::SUCCESS;
    }
}
