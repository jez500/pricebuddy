<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InitDatabase extends Command
{
    const COMMAND = 'buddy:init-db';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND;

    /**
     * The console command description.
     */
    protected $description = 'Initialize the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            DB::connection()->getPdo();
            $hasTables = Schema::hasTable('migrations');
        } catch (Exception $e) {
            $this->getOutput()->error('Database connection failed, check environment settings');

            return self::FAILURE;
        }

        if ($hasTables) {
            $this->getOutput()->info('Database already initialized');

            $this->components->task('Applying database updates', function () {
                $this->callSilent('migrate', ['--force' => true]);
            });

            // @todo sync stores?
        } else {
            $this->getOutput()->info('Database exists, but not initialized');

            $this->components->task('Setting up the database', fn () => $this
                ->callSilent('migrate:fresh', ['--force' => true])
            );

            // @phpstan-ignore-next-line
            $storeCountry = env('DEFAULT_STORES_COUNTRY', 'all');
            $this->components->task('Creating stores for country: '.$storeCountry, fn () => $this
                ->callSilent(CreateStores::COMMAND, ['country' => $storeCountry])
            );

            // @phpstan-ignore-next-line
            $email = env('APP_USER_EMAIL');
            // @phpstan-ignore-next-line
            $pass = env('APP_USER_PASSWORD');
            if ($email && $pass) {
                $this->line('Creating new user with email: '.$email.' and password: '.$pass);
                // @phpstan-ignore-next-line
                $name = env('APP_USER_NAME', 'Admin');
                $this->components->task('Creating the default user', fn () => $this
                    ->createDefaultUser($name, $email, $pass)->exists
                );
            }
        }

        $this->getOutput()->success('Database init complete');

        return self::SUCCESS;
    }

    /**
     * Create the initial user for a fresh install.
     *
     * The first user of an install must be an Admin so they can access
     * admin-only features (e.g. the Settings menu). The `role` column
     * defaults to "user", so it is set explicitly here rather than relying
     * on `make:filament-user`, which never sets a role.
     */
    protected function createDefaultUser(string $name, string $email, string $password): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => Role::Admin,
        ]);
    }
}
