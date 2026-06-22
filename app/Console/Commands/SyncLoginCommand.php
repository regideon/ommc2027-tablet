<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncLoginCommand extends Command
{
    protected $signature   = 'sync:login {--email= : User email} {--password= : User password}';
    protected $description = 'Authenticate this tablet with the server and store the API token.';

    public function handle(): int
    {
        $email    = $this->option('email')    ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');

        $response = Http::baseUrl(config('services.sync.url'))
            ->acceptJson()
            ->timeout(15)
            ->post('/api/auth/tablet-login', compact('email', 'password'));

        if (! $response->successful()) {
            $this->error('Login failed: ' . $response->json('error', $response->body()));
            return self::FAILURE;
        }

        $data = $response->json();

        $user = User::updateOrCreate(
            ['email' => $data['email']],
            [
                'name'      => $data['name'],
                'password'  => $data['password'],
                'api_token' => $data['api_token'],
            ]
        );

        $this->info("Logged in as {$user->name}. API token stored.");
        return self::SUCCESS;
    }
}
