<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Artisan::command('pos:create-user {email}', function (): int {
    $email = strtolower(trim((string) $this->argument('email')));
    $name = trim((string) $this->ask('Display name'));
    $password = $this->secret('Password (12–72 bytes)');
    $confirmation = $this->secret('Confirm password');
    $validator = \Illuminate\Support\Facades\Validator::make(
        compact('email', 'name', 'password') + ['password_confirmation' => $confirmation],
        [
            'email' => ['required', 'email:rfc', 'max:254', 'unique:users,email'],
            'name' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]
    );

    if ($validator->fails() || strlen((string) $password) > 72) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }
        if (strlen((string) $password) > 72) {
            $this->error('The password must not exceed 72 bytes.');
        }
        return 1;
    }

    \App\Models\User::create(compact('email', 'name', 'password'));
    $this->info('POS user created. Public sign-up is disabled.');
    return 0;
})->purpose('Create a local POS account without a public registration page');
