<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * php artisan visitdasol:demo-reset
 *
 * Puts the system back to its demo state: empty database, demo accounts,
 * demo resorts and bookings. Use it before a presentation, so every demo
 * starts from the same known point.
 *
 * It ERASES EVERYTHING, so it refuses to run on a live site.
 */
class ResetDemo extends Command
{
    protected $signature = 'visitdasol:demo-reset {--force : Do not ask for confirmation}';

    protected $description = 'Erase everything and reload the demo data (never on a live site)';


    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run: APP_ENV is production. This command erases every account and booking.');

            return self::FAILURE;
        }

        if (blank(config('visitdasol.demo.password'))) {
            $this->error('Set DEMO_ACCOUNT_PASSWORD in .env first (8+ characters). The demo accounts use it.');

            return self::FAILURE;
        }

        if (blank(config('visitdasol.mto_admin.email')) || blank(config('visitdasol.mto_admin.password'))) {
            $this->error('Set MTO_ADMIN_EMAIL and MTO_ADMIN_PASSWORD in .env first. The office account uses them.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This erases ALL accounts, resorts, bookings, and uploaded photos. Continue?')) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        // Uploaded files belong to records that are about to disappear.
        // Photos live on the public disk; permit documents on the private one.
        $folders = config('visitdasol.uploads.folders');

        foreach (['resorts', 'rooms', 'destinations', 'announcements'] as $key) {
            Storage::disk('public')->deleteDirectory($folders[$key]);
        }
        Storage::disk('local')->deleteDirectory($folders['owner_documents']);

        // Demo data is loaded for this run even if SEED_DEMO_DATA is false in .env.
        config(['visitdasol.demo.enabled' => true]);

        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->newLine();
        $this->info('Demo data is ready. Sign in with:');

        $domain = config('visitdasol.demo.email_domain');

        $this->table(['Role', 'Email', 'Password'], [
            ['Tourism office', config('visitdasol.mto_admin.email'), 'MTO_ADMIN_PASSWORD from .env'],
            ['Resort owner', "owner.saltbreeze@{$domain}", 'DEMO_ACCOUNT_PASSWORD from .env'],
            ['Owner, application waiting', "owner.pending@{$domain}", '(cannot sign in until approved)'],
            ['Tourist', "tourist.bea@{$domain}", 'DEMO_ACCOUNT_PASSWORD from .env'],
        ]);

        return self::SUCCESS;
    }
}
