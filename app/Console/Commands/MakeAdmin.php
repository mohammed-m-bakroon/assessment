<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'app:make-admin {email : The user\'s email address} {--revoke : Remove admin access instead}';

    protected $description = 'Give a user access to the operator report (or revoke it)';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user with that email. They need to register first.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($user->is_admin ? "{$user->email} is now an admin." : "{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
