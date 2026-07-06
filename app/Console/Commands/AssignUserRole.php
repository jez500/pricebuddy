<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

use function Laravel\Prompts\search;
use function Laravel\Prompts\select;

class AssignUserRole extends Command implements PromptsForMissingInput
{
    const COMMAND = 'user:assign-role';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {email : The email of the user to assign the role to}'.
        ' {role : The role to assign to the user}';

    /**
     * The console command description.
     */
    protected $description = 'Assign a role to the user with the given email';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $role = Role::tryFrom($this->argument('role'));

        if ($role === null) {
            $validRoles = implode(', ', array_column(Role::cases(), 'value'));
            $this->components->error("Invalid role '{$this->argument('role')}'. Valid roles: {$validRoles}.");

            return self::FAILURE;
        }

        $user = User::whereEmail($email)->first();

        if ($user === null) {
            $this->components->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        $user->update(['role' => $role]);

        $this->components->info("Assigned the '{$role->value}' role to {$user->email}.");

        return self::SUCCESS;
    }

    /**
     * Prompt for any missing arguments interactively.
     *
     * @return array<string, \Closure>
     */
    protected function promptForMissingArgumentsUsing(): array
    {
        return [
            'email' => fn () => search(
                label: 'Which user would you like to assign a role to?',
                options: fn (string $value) => User::query()
                    ->when($value !== '', fn ($query) => $query
                        ->where('email', 'like', "%{$value}%")
                        ->orWhere('name', 'like', "%{$value}%")
                    )
                    ->orderBy('name')
                    ->limit(10)
                    ->get()
                    ->mapWithKeys(fn (User $user) => [$user->email => "{$user->name} ({$user->email})"])
                    ->all(),
                placeholder: 'Search by name or email',
            ),
            'role' => fn () => select(
                label: 'Which role would you like to assign?',
                options: collect(Role::cases())
                    ->mapWithKeys(fn (Role $role) => [$role->value => $role->name])
                    ->all(),
            ),
        ];
    }
}
