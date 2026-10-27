<?php

namespace App\Http\Controllers\Dev;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\DestinationCategory;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PDO;
use Throwable;

/**
 * Phase 1 diagnostics page: PHP, database, storage, and frontend assets.
 *
 * The route is registered only when APP_ENV=local, and this controller
 * refuses to run anywhere else. It never displays passwords or keys.
 */
class SetupCheckController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(app()->isLocal(), 404);

        $groups = [
            'Application' => $this->applicationChecks($request),
            'PHP extensions' => $this->extensionChecks(),
            'Database' => $this->databaseChecks(),
            'Database schema and seed data' => $this->schemaChecks(),
            'Storage' => $this->storageChecks(),
            'Frontend assets' => $this->assetChecks(),
        ];

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($groups as $checks) {
            foreach ($checks as $check) {
                $summary[$check['status']]++;
            }
        }

        return view('dev.setup-check', compact('groups', 'summary'));
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function result(string $label, bool|string $status, string $detail = ''): array
    {
        if (is_bool($status)) {
            $status = $status ? 'pass' : 'fail';
        }

        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }

    private function applicationChecks(Request $request): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $currentUrl = $request->getSchemeAndHttpHost();
        $barangayCount = count(config('visitdasol.barangays', []));

        return [
            $this->result(
                'PHP version',
                version_compare(PHP_VERSION, '8.2.0', '>='),
                'Running PHP '.PHP_VERSION.'. Laravel 12 needs 8.2 or newer.'
            ),
            $this->result('Laravel version', 'pass', 'Laravel '.Application::VERSION),
            $this->result(
                'Application key',
                filled(config('app.key')),
                filled(config('app.key')) ? 'APP_KEY is set.' : 'Run: php artisan key:generate'
            ),
            $this->result(
                'Application name',
                config('app.name') === 'Visit Dasol' ? 'pass' : 'warn',
                'APP_NAME is "'.config('app.name').'". Expected "Visit Dasol".'
            ),
            $this->result(
                'Timezone',
                config('app.timezone') === 'Asia/Manila',
                'Using '.config('app.timezone').'. Local time now: '.now()->format('M j, Y g:i A')
            ),
            $this->result(
                'APP_URL matches this address',
                $appUrl === $currentUrl ? 'pass' : 'warn',
                $appUrl === $currentUrl
                    ? "APP_URL is {$appUrl}."
                    : "APP_URL is {$appUrl} but you opened {$currentUrl}. Uploaded image links are built from APP_URL, so set it to the address you use."
            ),
            $this->result(
                'Debug mode',
                config('app.debug') ? 'pass' : 'warn',
                config('app.debug')
                    ? 'APP_DEBUG=true (expected while developing; turn it off for demonstrations).'
                    : 'APP_DEBUG=false. Error details are hidden.'
            ),
            $this->result(
                'Visit Dasol config',
                $barangayCount === 18,
                "config/visitdasol.php loaded with {$barangayCount} barangays."
            ),
        ];
    }

    private function extensionChecks(): array
    {
        $required = ['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'tokenizer', 'ctype', 'dom', 'xml', 'curl', 'filter', 'session'];
        $recommended = [
            'intl' => 'Used for locale-aware number and currency formatting.',
            'zip' => 'Lets Composer unpack packages faster.',
            'gd' => 'Useful for image handling in later phases.',
        ];

        $checks = [];

        foreach ($required as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->result(
                $extension,
                $loaded,
                $loaded ? 'Loaded.' : "Required. Enable extension={$extension} in C:\\xampp\\php\\php.ini, then restart Apache."
            );
        }

        foreach ($recommended as $extension => $why) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->result(
                $extension,
                $loaded ? 'pass' : 'warn',
                $loaded ? 'Loaded.' : "Recommended. {$why}"
            );
        }

        return $checks;
    }

    private function databaseChecks(): array
    {
        $connection = DB::connection();

        try {
            $pdo = $connection->getPdo();
        } catch (Throwable $e) {
            return [
                $this->result(
                    'Connection',
                    'fail',
                    'Could not connect: '.$e->getMessage().' Check that MySQL is running in the XAMPP Control Panel and that DB_DATABASE, DB_USERNAME, and DB_PASSWORD in .env are correct.'
                ),
            ];
        }

        $driver = $connection->getDriverName();
        $checks = [
            $this->result(
                'Connection',
                'pass',
                'Connected to database "'.$connection->getDatabaseName().'" using the '.$driver.' driver.'
            ),
            $this->result(
                'Driver',
                in_array($driver, ['mysql', 'mariadb'], true) ? 'pass' : 'warn',
                in_array($driver, ['mysql', 'mariadb'], true)
                    ? 'MySQL/MariaDB driver in use.'
                    : "DB_CONNECTION is {$driver}. This project uses MySQL; set DB_CONNECTION=mysql."
            ),
            $this->result('Server version', 'pass', (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)),
        ];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $row = $connection->selectOne('SELECT @@character_set_database AS charset, @@collation_database AS collation, @@default_storage_engine AS engine');

            $checks[] = $this->result(
                'Database character set',
                $row->charset === 'utf8mb4' ? 'pass' : 'warn',
                "{$row->charset} / {$row->collation}. Expected utf8mb4 so names like Osmeña and the ₱ sign store correctly."
            );
            $checks[] = $this->result(
                'Storage engine',
                strcasecmp($row->engine, 'InnoDB') === 0,
                "{$row->engine}. InnoDB is required for foreign keys and booking row locks."
            );
        }

        $hasMigrations = Schema::hasTable('migrations');
        $checks[] = $this->result(
            'Migrations table',
            $hasMigrations ? 'pass' : 'warn',
            $hasMigrations ? 'Found.' : 'Not found yet. Run: php artisan migrate'
        );

        if (config('session.driver') === 'database') {
            $hasSessions = Schema::hasTable(config('session.table', 'sessions'));
            $checks[] = $this->result(
                'Sessions table',
                $hasSessions,
                $hasSessions ? 'Found (SESSION_DRIVER=database).' : 'Missing. Run: php artisan migrate'
            );
        }

        return $checks;
    }

    private function schemaChecks(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            return [$this->result('Schema', 'fail', 'Skipped because the database connection failed.')];
        }

        $tables = [
            'users', 'owner_profiles', 'resorts', 'resort_images', 'amenities', 'amenity_resort',
            'rooms', 'room_images', 'bookings', 'destination_categories', 'destinations',
            'destination_images', 'announcements', 'activity_logs',
        ];
        $missing = array_values(array_filter($tables, fn (string $table) => ! Schema::hasTable($table)));

        $checks = [
            $this->result(
                'Visit Dasol tables',
                $missing === [],
                $missing === []
                    ? count($tables).' tables found.'
                    : 'Missing: '.implode(', ', $missing).'. Run: php artisan migrate:fresh --seed'
            ),
        ];

        if ($missing !== []) {
            return $checks;
        }

        if (! Schema::hasColumns('users', ['first_name', 'role', 'status'])) {
            $checks[] = $this->result('Users table', 'fail', 'Still the Laravel default users table. Run: php artisan migrate:fresh --seed');

            return $checks;
        }

        $admins = User::query()->withRole(UserRole::SuperAdmin)->active()->count();
        $checks[] = $this->result(
            'MTO account',
            $admins > 0,
            $admins > 0 ? "{$admins} active MTO account(s)." : 'None yet. Set MTO_ADMIN_EMAIL and MTO_ADMIN_PASSWORD in .env, then run: php artisan db:seed'
        );

        $amenities = Amenity::query()->count();
        $checks[] = $this->result('Amenities', $amenities > 0 ? 'pass' : 'warn', "{$amenities} amenities in the master list.");

        $categories = DestinationCategory::query()->count();
        $checks[] = $this->result('Destination categories', $categories > 0 ? 'pass' : 'warn', "{$categories} categories.");

        $demoAccounts = User::query()->where('email', 'like', '%@'.config('visitdasol.demo.email_domain'))->count();
        $checks[] = $this->result(
            'Demo data',
            'pass',
            $demoAccounts > 0
                ? "{$demoAccounts} DEMO accounts present (fictional data for development)."
                : 'No demo data. Set SEED_DEMO_DATA=true and run: php artisan migrate:fresh --seed'
        );

        return $checks;
    }

    private function storageChecks(): array
    {
        $link = public_path('storage');
        $target = storage_path('app/public');
        $linked = file_exists($link) && realpath($link) === realpath($target);

        /*
         * A real folder sitting where the link belongs is the trap worth naming.
         * On Windows, symlink() needs Developer Mode or an elevated prompt, so
         * "php artisan storage:link" often fails and the folder gets created by
         * hand instead. From then on the command reports "link already exists"
         * and changes nothing, while every uploaded photo 404s. A junction is
         * the way out: it needs no special rights.
         */
        $checks = [
            $this->result(
                'public/storage link',
                $linked,
                match (true) {
                    $linked => 'public/storage points to storage/app/public.',
                    is_dir($link) => 'public/storage is a REAL FOLDER, not a link, so uploaded photos cannot be reached. '
                        ."\"php artisan storage:link\" will not fix this; it reports that the link already exists. Delete the empty {$link} folder, then on Windows run: "
                        .'cmd /c mklink /J "'.$link.'" "'.$target.'" '
                        .'(a junction needs no administrator rights, unlike a symbolic link). Elsewhere run: php artisan storage:link',
                    default => 'Missing. Run: php artisan storage:link',
                }
            ),
        ];

        $writable = [
            'storage/app/public' => storage_path('app/public'),
            'storage/app/private' => storage_path('app/private'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        foreach ($writable as $label => $path) {
            $ok = is_dir($path) && is_writable($path);
            $checks[] = $this->result($label.' writable', $ok, $ok ? 'Writable.' : 'Not writable. Check folder permissions.');
        }

        $folders = config('visitdasol.uploads.folders');
        foreach (['resorts', 'rooms', 'destinations', 'announcements'] as $key) {
            $exists = Storage::disk('public')->directoryExists($folders[$key]);
            $checks[] = $this->result(
                "Public folder: {$folders[$key]}",
                $exists ? 'pass' : 'warn',
                $exists ? 'Exists.' : 'Missing. Laravel creates it on first upload, but it should be in the Phase 1 structure.'
            );
        }

        $privateExists = Storage::disk('local')->directoryExists($folders['owner_documents']);
        $checks[] = $this->result(
            "Private folder: {$folders['owner_documents']}",
            $privateExists ? 'pass' : 'warn',
            $privateExists ? 'Exists (not web-accessible).' : 'Missing. Laravel creates it on first upload.'
        );

        $checks[] = $this->result(
            'Private disk serving',
            config('filesystems.disks.local.serve') ? 'warn' : 'pass',
            config('filesystems.disks.local.serve')
                ? "The 'local' disk has 'serve' => true. Set it to false in config/filesystems.php; permits are streamed by an MTO-only route instead."
                : 'Private files are not served directly.'
        );

        try {
            $disk = Storage::disk('public');
            $probe = '.setup-check-'.bin2hex(random_bytes(4)).'.txt';
            $disk->put($probe, 'ok');
            $roundTrip = $disk->get($probe) === 'ok';
            $disk->delete($probe);
            $checks[] = $this->result('Public disk write test', $roundTrip, $roundTrip ? 'Wrote, read, and deleted a test file.' : 'Test file could not be read back.');
        } catch (Throwable $e) {
            $checks[] = $this->result('Public disk write test', 'fail', $e->getMessage());
        }

        return $checks;
    }

    private function assetChecks(): array
    {
        $assets = [
            'Bootstrap CSS' => 'vendor/bootstrap/css/bootstrap.min.css',
            'Bootstrap JS bundle' => 'vendor/bootstrap/js/bootstrap.bundle.min.js',
            'Bootstrap Icons' => 'vendor/bootstrap-icons/bootstrap-icons.min.css',
            'Bootstrap Icons font' => 'vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
            'Leaflet CSS' => 'vendor/leaflet/leaflet.css',
            'Leaflet JS' => 'vendor/leaflet/leaflet.js',
            'Leaflet marker images' => 'vendor/leaflet/images/marker-icon.png',
            'Fonts stylesheet' => 'vendor/fonts/fonts.css',
            'Visit Dasol CSS' => 'css/visitdasol.css',
            'Visit Dasol JS' => 'js/app.js',
            'Logo' => 'images/logo.svg',
        ];

        $checks = [];
        foreach ($assets as $label => $path) {
            $ok = is_file(public_path($path));
            $checks[] = $this->result($label, $ok, $ok ? "public/{$path}" : "Missing: public/{$path}");
        }

        return $checks;
    }
}
