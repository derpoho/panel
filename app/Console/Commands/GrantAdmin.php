<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantAdmin extends Command
{
    protected $signature = 'admin:grant {email : The account to make an admin} {--revoke : Take admin away instead}';

    protected $description = 'Let someone open the ServerOS admin pages (or stop them)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user === null) {
            $this->error("No account uses {$email}. They need to sign up first.");

            return self::FAILURE;
        }

        $admin = ! $this->option('revoke');
        $user->forceFill(['is_admin' => $admin])->save();

        if (! $admin) {
            $this->info("{$user->email} is no longer an admin.");
        } elseif ($user->email_verified_at === null) {
            $this->warn("{$user->email} is an admin, but can't open /admin until their email is verified.");
        } else {
            $this->info("{$user->email} is an admin.");
        }

        return self::SUCCESS;
    }
}
