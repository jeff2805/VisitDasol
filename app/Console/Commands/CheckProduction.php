<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * php artisan visitdasol:check-production
 *
 * Checks the settings that matter before Visit Dasol goes live and prints
 * what to fix. Exits with code 1 if anything would be unsafe, so it can be
 * part of a deployment script.
 *
 * Nothing is changed; it only reads.
 */
class CheckProduction extends Command
{
    protected $signature = 'visitdasol:check-production';

    protected $description = 'Check that Visit Dasol is safe to run as a live site';

    /** @var list<array{0: string, 1: string, 2: string}> [result, check, advice] */
    private array $results = [];

    public function handle(): int
    {
        $this->info('Visit Dasol production check');
        $this->newLine();

        $this->checkEnvironment();
        $this->checkHttpsAndCookies();
        $this->checkMail();
        $this->checkDatabaseAndAccounts();
        $this->checkFilesAndFolders();
        $this->checkPhp();
        $this->checkCaches();

        $this->table(['', 'Check', 'What to do'], array_map(fn (array $row) => [
            match ($row[0]) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                default => '<fg=red>FAIL</>',
            },
            $row[1],
            $row[2],
        ], $this->results));

        $failures = count(array_filter($this->results, fn (array $row) => $row[0] === 'fail'));
        $warnings = count(array_filter($this->results, fn (array $row) => $row[0] === 'warn'));

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} ".str('problem')->plural($failures)." must be fixed before going live.");

            return self::FAILURE;
        }

        $warnings > 0
            ? $this->warn("Ready, with {$warnings} ".str('warning')->plural($warnings).' worth reviewing.')
            : $this->info('Ready to go live.');

        return self::SUCCESS;
    }

    private function checkEnvironment(): void
    {
        $this->record(app()->environment('production'), 'APP_ENV is production',
            'Set APP_ENV=production in .env');

        $this->record(! config('app.debug'), 'Debug mode is off',
            'Set APP_DEBUG=false. With it on, errors show passwords and code to visitors');

        $this->record(filled(config('app.key')), 'Application key is set',
            'Run php artisan key:generate on the server (never copy it from development)');
    }

    private function checkHttpsAndCookies(): void
    {
        $https = str_starts_with((string) config('app.url'), 'https://');

        $this->record($https, 'Site address uses HTTPS',
            'Serve the site over HTTPS and set APP_URL=https://...', warnOnly: true);

        if ($https) {
            $this->record((bool) config('session.secure'), 'Session cookie is HTTPS-only',
                'Set SESSION_SECURE_COOKIE=true');
        }
    }

    private function checkMail(): void
    {
        $mailer = (string) config('mail.default');

        $this->record(! in_array($mailer, ['log', 'array'], true), 'Email is actually sent',
            "MAIL_MAILER is {$mailer}, so password reset emails never reach anyone. Configure SMTP",
            warnOnly: true);
    }

    private function checkDatabaseAndAccounts(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->record(false, 'Database connection works', 'Check the DB_ settings in .env');

            return;
        }

        $this->record(true, 'Database connection works', '');

        $office = User::query()
            ->where('role', UserRole::SuperAdmin->value)
            ->where('status', UserStatus::Active->value)
            ->exists();

        $this->record($office, 'An active tourism office account exists',
            'Run php artisan db:seed --class=SuperAdminSeeder');

        $demo = User::query()->where('email', 'like', '%@'.config('visitdasol.demo.email_domain'))->count();

        $this->record($demo === 0, 'No demo accounts in the database',
            "{$demo} demo accounts found. Start from a clean database: set SEED_DEMO_DATA=false and run php artisan migrate:fresh --seed");

        $this->record(! config('visitdasol.demo.enabled'), 'Demo data is switched off',
            'Set SEED_DEMO_DATA=false', warnOnly: true);

        $this->record(blank(config('visitdasol.mto_admin.password')), 'Office password removed from .env',
            'After the office account exists and the password has been changed, delete MTO_ADMIN_PASSWORD from .env',
            warnOnly: true);
    }

    private function checkFilesAndFolders(): void
    {
        $this->record(file_exists(public_path('storage')), 'Uploaded photos are reachable',
            'Run php artisan storage:link');

        foreach ([storage_path(), base_path('bootstrap/cache')] as $folder) {
            $this->record(is_writable($folder), 'Writable: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $folder),
                'Give the web server permission to write to this folder');
        }
    }

    private function checkPhp(): void
    {
        $this->record(version_compare(PHP_VERSION, '8.2.0', '>='), 'PHP '.PHP_VERSION.' (8.2 or newer)',
            'Upgrade PHP to 8.2 or newer');

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'tokenizer', 'xml', 'ctype'] as $extension) {
            $this->record(extension_loaded($extension), "PHP extension {$extension}",
                "Enable extension={$extension} in php.ini");
        }

        $needed = (int) ceil(max(config('visitdasol.uploads.image_max_kb'), config('visitdasol.uploads.document_max_kb')) / 1024);

        $this->record($this->megabytes((string) ini_get('upload_max_filesize')) >= $needed,
            "upload_max_filesize is at least {$needed}M",
            "Set upload_max_filesize={$needed}M in php.ini", warnOnly: true);

        $this->record($this->megabytes((string) ini_get('post_max_size')) >= $needed * 4,
            'post_max_size is at least '.($needed * 4).'M',
            'Set post_max_size='.($needed * 4).'M in php.ini (several photos can be sent at once)', warnOnly: true);
    }

    private function checkCaches(): void
    {
        $this->record(app()->configurationIsCached(), 'Configuration is cached',
            'Run php artisan config:cache (and again after every .env change)', warnOnly: true);

        $this->record(app()->routesAreCached(), 'Routes are cached',
            'Run php artisan route:cache', warnOnly: true);
    }

    private function record(bool $passed, string $check, string $advice, bool $warnOnly = false): void
    {
        $this->results[] = [$passed ? 'pass' : ($warnOnly ? 'warn' : 'fail'), $check, $passed ? '' : $advice];
    }

    private function megabytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024,
            'K' => (int) ceil($number / 1024),
            'M' => $number,
            default => (int) ceil($number / 1048576),
        };
    }
}
