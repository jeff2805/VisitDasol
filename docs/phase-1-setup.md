# Visit Dasol: Phase 1 setup

These files are an overlay for a fresh Laravel 12 project. Follow the steps in order.

## 1. Check PHP

```bash
php -v        # 8.2 or newer
composer -V
php -m        # look for pdo_mysql, fileinfo, mbstring, openssl, zip, intl
```

In `C:\xampp\php\php.ini`, remove the leading `;` from these lines if present, then restart Apache:

```
extension=fileinfo
extension=zip
extension=intl
```

## 2. Create the Laravel project

```bash
cd C:\xampp\htdocs
composer create-project laravel/laravel:^12.0 visitdasol
cd visitdasol
```

Composer may create `database/database.sqlite`. It is unused; you can delete it.

## 3. Create the database

In phpMyAdmin, open the SQL tab and run:

```sql
CREATE DATABASE visitdasol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Create only the database. Tables are always created by Laravel migrations.

## 4. Copy the Phase 1 files

Copy everything inside the `visitdasol-phase1` folder into `C:\xampp\htdocs\visitdasol`.
Allow it to replace `routes/web.php` and `.env.example`.

## 5. Edit two Laravel config files

`config/app.php`: REPLACE

```php
'timezone' => 'UTC',
```

with

```php
'timezone' => env('APP_TIMEZONE', 'Asia/Manila'),
```

`config/filesystems.php`: inside the `'local' => [` disk, REPLACE

```php
'serve' => true,
```

with

```php
'serve' => false,
```

## 6. Remove the unused Vite files (PowerShell, in the project folder)

```powershell
Remove-Item vite.config.js, package.json, resources\views\welcome.blade.php
Remove-Item -Recurse resources\css, resources\js
```

## 7. Update .env

Open `.env` and copy every value from the new `.env.example` **except `APP_KEY`**
(keep the key Laravel generated). The important lines:

```
APP_NAME="Visit Dasol"
APP_TIMEZONE=Asia/Manila
APP_URL=http://visitdasol.test
APP_FAKER_LOCALE=en_PH
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=visitdasol
DB_USERNAME=root
DB_PASSWORD=
```

## 8. Run the setup commands

```bash
php artisan config:clear
php artisan migrate
php artisan storage:link
```

## 9. Apache virtual host

Add to `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
</VirtualHost>

<VirtualHost *:80>
    ServerName visitdasol.test
    DocumentRoot "C:/xampp/htdocs/visitdasol/public"
    <Directory "C:/xampp/htdocs/visitdasol/public">
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Open Notepad **as Administrator**, open `C:\Windows\System32\drivers\etc\hosts`, and add:

```
127.0.0.1 visitdasol.test
```

Restart Apache in the XAMPP Control Panel.

No Apache yet? Run `php artisan serve` and set `APP_URL=http://127.0.0.1:8000` instead.

## 10. Test

| Open | Expected |
|---|---|
| http://visitdasol.test/ | Home page with the "Visit Dasol" hero; later-phase links appear disabled |
| /_dev/setup | 0 failed checks; map with a pin and dashed boundary (needs internet); dialog button works |
| /_dev/preview/owner | Owner dashboard shell with sidebar items marked "Soon" |
| /_dev/preview/admin | MTO dashboard shell with approval cards showing 3 and 2 |
| /_dev/preview/auth | Split-screen sign-in layout |
| /_dev/preview/error/404 and /403 | Custom error pages |

Sidebar count badges appear only on links whose routes exist, so they first show in Phase 4.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Every page shows a 500 error | Open /_dev/setup; it still works when the database is misconfigured and names the problem |
| "Unknown database 'visitdasol'" | Step 3 was skipped |
| Uploaded images later show as broken | `APP_URL` must match the address in your browser |
| visitdasol.test does not open | Check the hosts file entry and restart Apache |
| phpMyAdmin stopped working | Keep the `localhost` block first in httpd-vhosts.conf |
| Changed .env but nothing happened | Run `php artisan config:clear` |

## Notes for later phases

- The `/_dev` routes exist only while `APP_ENV=local` and are removed in Phase 21.
- Laravel's `local` disk (`storage/app/private`) is the project's private disk.
- Phase 2 replaces the default `users` migration and runs `php artisan migrate:fresh`.
