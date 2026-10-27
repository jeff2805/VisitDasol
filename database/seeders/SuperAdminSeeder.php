<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

/**
 * Creates the first Municipal Tourism Office (super_admin) account from .env:
 *   MTO_ADMIN_EMAIL, MTO_ADMIN_PASSWORD, MTO_ADMIN_FIRST_NAME, MTO_ADMIN_LAST_NAME
 *
 * Safe to run more than once:
 * - an existing MTO account keeps its current password;
 * - if the email already belongs to a tourist or owner, seeding stops
 *   instead of promoting that account.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $settings = config('visitdasol.mto_admin');
        $email = Str::lower(trim((string) ($settings['email'] ?? '')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Set MTO_ADMIN_EMAIL in .env to a valid email address, run "php artisan config:clear", and seed again.');
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            if (! $existing->isSuperAdmin()) {
                throw new RuntimeException("The email {$email} already belongs to a non-MTO account. Choose a different MTO_ADMIN_EMAIL.");
            }

            if (! $existing->isActive()) {
                $existing->status = UserStatus::Active;
                $existing->save();
            }

            $this->command?->info("MTO account {$email} already exists. Its password was not changed.");

            return;
        }

        $password = (string) ($settings['password'] ?? '');

        if ($password === '') {
            throw new RuntimeException('Set MTO_ADMIN_PASSWORD in .env (12+ characters with upper- and lowercase letters and a number), run "php artisan config:clear", and seed again.');
        }

        $validator = Validator::make(
            ['password' => $password],
            ['password' => [Password::min(12)->letters()->mixedCase()->numbers()]],
        );

        if ($validator->fails()) {
            throw new RuntimeException(
                'MTO_ADMIN_PASSWORD is not strong enough: '.implode(' ', $validator->errors()->all())
                .' Update .env, run "php artisan config:clear", and seed again.'
            );
        }

        $admin = new User([
            'first_name' => $settings['first_name'] ?: 'Tourism',
            'last_name' => $settings['last_name'] ?: 'Office',
            'email' => $email,
            'phone' => $settings['phone'] ?: null,
            'password' => $password,
        ]);
        $admin->role = UserRole::SuperAdmin;
        $admin->status = UserStatus::Active;
        $admin->email_verified_at = now();
        $admin->save();

        $this->command?->info("MTO account created: {$email}");
    }
}
