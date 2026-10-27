# Visit Dasol: installing and running it

This guide takes a Windows computer with nothing installed to a working Visit Dasol,
then covers showing it on other devices, going live, and backups.

Use the **complete project zip** (`visitdasol-complete.zip`). It already contains all
21 phases, so there is nothing to layer on top.

---

## Part 1. Install on XAMPP (Windows)

### 1.1 Install the tools

1. **XAMPP with PHP 8.2** from apachefriends.org. Install to `C:\xampp`.
   PHP 8.3 also works; 8.1 and older do not.
2. **Composer** from getcomposer.org. When the installer asks for PHP, choose
   `C:\xampp\php\php.exe`.

Open a new Command Prompt and check both:

```
php -v
composer -V
```

### 1.2 Check `php.ini`

Open `C:\xampp\php\php.ini` in Notepad. Make sure these lines exist **without a `;`
in front** (remove the `;` if there is one):

```
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_mysql
extension=zip
```

Then set the upload limits, so owners can send photos and permits:

```
upload_max_filesize=8M
post_max_size=24M
```

For speed, also turn on opcache (it is usually present but commented out):

```
zend_extension=opcache
opcache.enable=1
```

Save, then restart Apache from the XAMPP Control Panel.

### 1.3 Put the project in place

Unzip `visitdasol-complete.zip` to `C:\xampp\htdocs\visitdasol`, then:

```
cd C:\xampp\htdocs\visitdasol
composer install
```

This downloads Laravel and the other libraries into `vendor\`. It needs internet
and takes a few minutes the first time.

### 1.4 Create the databases

Start **Apache** and **MySQL** in the XAMPP Control Panel, open
http://localhost/phpmyadmin, choose the **SQL** tab, and run:

```sql
CREATE DATABASE visitdasol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE visitdasol_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

The second one is used only by the automated tests.

### 1.5 Configure `.env`

```
copy .env.example .env
```

Open `.env` and set:

```
APP_URL=http://visitdasol.test

DB_DATABASE=visitdasol
DB_USERNAME=root
DB_PASSWORD=

MTO_ADMIN_EMAIL=mto@visitdasol.test
MTO_ADMIN_PASSWORD=choose-a-strong-one-2026

SEED_DEMO_DATA=true
DEMO_ACCOUNT_PASSWORD=choose-one-too-2026
```

`MTO_ADMIN_PASSWORD` needs 12+ characters with upper and lower case letters and a
number. `DEMO_ACCOUNT_PASSWORD` needs 8+ characters.

### 1.6 Build the database

```
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
```

If `storage:link` fails with a permission error, close the Command Prompt, open it
again with **Run as administrator**, and repeat that one command. Windows needs
administrator rights to create the link.

### 1.7 Give it an address: `http://visitdasol.test`

**a. Apache virtual host.** Open `C:\xampp\apache\conf\extra\httpd-vhosts.conf`
and add at the end:

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
</VirtualHost>

<VirtualHost *:80>
    ServerName visitdasol.test
    DocumentRoot "C:/xampp/htdocs/visitdasol/public"
    <Directory "C:/xampp/htdocs/visitdasol/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

The first block keeps http://localhost/phpmyadmin working. Note that
`DocumentRoot` points at the **`public`** folder, never the project folder itself:
that keeps `.env` and the code out of reach of the browser.

**b. Two lines in `httpd.conf`.** Open `C:\xampp\apache\conf\httpd.conf` and make
sure these have no `#` in front:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
Include conf/extra/httpd-vhosts.conf
```

**c. The hosts file.** Open Notepad **as administrator**, open
`C:\Windows\System32\drivers\etc\hosts`, and add:

```
127.0.0.1   visitdasol.test
```

Restart Apache, then open **http://visitdasol.test**.

### 1.8 Check the installation

- Open **http://visitdasol.test/_dev/setup**. This page (only available while
  `APP_ENV=local`) checks PHP, extensions, the database, folders, and the map.
  Everything should be green.
- Run the automated tests:

  ```
  php artisan test
  ```

  Expected: **412 tests pass.**

### 1.9 Sign in

| Role | Email | Password |
|---|---|---|
| Tourism office | `mto@visitdasol.test` | your `MTO_ADMIN_PASSWORD` |
| Resort owner | `owner.saltbreeze@demo.visitdasol.test` | your `DEMO_ACCOUNT_PASSWORD` |
| Tourist | `tourist.bea@demo.visitdasol.test` | your `DEMO_ACCOUNT_PASSWORD` |

All demo accounts are listed in `docs/phase-2-database.md`.

---

## Part 2. Troubleshooting

| What you see | Cause | Fix |
|---|---|---|
| Blank page or "500 Server Error" | Usually `.env` or the key | Run `php artisan key:generate`, then `php artisan config:clear`. Check `storage\logs\laravel.log` for the real message |
| Every page except the home page is "Not Found" | `mod_rewrite` off, or `AllowOverride` missing | Step 1.7b and the `AllowOverride All` line in 1.7a |
| The browser shows a list of files | `DocumentRoot` points at the project, not `public` | Step 1.7a |
| `visitdasol.test` doesn't load at all | hosts file not saved as administrator | Step 1.7c |
| "could not find driver" | `pdo_mysql` disabled | Step 1.2, then restart Apache |
| "Access denied for user 'root'" | Wrong database password | Check `DB_PASSWORD` in `.env` (empty by default on XAMPP) |
| "Unknown database 'visitdasol'" | Database not created | Step 1.4 |
| Photos upload but don't show | Storage link missing | `php artisan storage:link` as administrator |
| "This page has expired" right after signing in | `APP_URL` doesn't match the address in the browser | Make them the same, then `php artisan config:clear` |
| "That upload was too large" | PHP's upload limits | Step 1.2, restart Apache |
| Map area is grey, but pins show | No internet: the map pictures come from OpenStreetMap | Connect to the internet. Everything else still works |
| Changes to `.env` do nothing | Configuration is cached | `php artisan config:clear` |
| Tests fail with "Unknown database 'visitdasol_test'" | Test database missing | Step 1.4 |

---

## Part 3. Showing it on other devices (for a presentation)

To let the panel try it on their phones over the same Wi-Fi:

1. Find your computer's address: run `ipconfig` and note the **IPv4 Address**,
   for example `192.168.1.25`.
2. In `.env`, set `APP_URL=http://192.168.1.25:8000`, then run
   `php artisan config:clear`.
3. Start the built-in server so other devices can reach it:

   ```
   php artisan serve --host=0.0.0.0 --port=8000
   ```

4. If Windows asks about the firewall, allow access on **private networks**.
5. On a phone on the same Wi-Fi, open `http://192.168.1.25:8000`.

Set `APP_URL` back to `http://visitdasol.test` afterwards.

Venue Wi-Fi often blocks devices from seeing each other. A phone hotspot from your
own phone, with the laptop and the panel's phones joined to it, is more reliable.

---

## Part 4. Before a presentation: reset the demo

```
php artisan visitdasol:demo-reset
```

This erases everything and reloads the demo accounts, resorts, and bookings, so
every run of the demo starts from the same state. It asks for confirmation, and it
**refuses to run** when `APP_ENV=production`. It takes a few seconds.

---

## Part 5. Going live

When the Municipal Tourism Office puts Visit Dasol on a real server:

### 5.1 Server

- PHP 8.2+, MySQL 8 or MariaDB 10.6+, Apache (with `mod_rewrite`) or Nginx.
- The web root must be the project's **`public`** folder.
- **HTTPS is required.** Most hosts offer a free Let's Encrypt certificate.
- The web server needs write access to `storage` and `bootstrap/cache`, and nothing
  else.

### 5.2 `.env` for a live site

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-real-address
SESSION_SECURE_COOKIE=true
SEED_DEMO_DATA=false
MAIL_MAILER=smtp            (plus the MAIL_ settings in .env.example)
```

Generate a **new** key on the server (`php artisan key:generate`); never copy the
development one.

### 5.3 Install

```
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`db:seed` creates the tourism office account, amenities, and destination
categories; with `SEED_DEMO_DATA=false` it adds no demo data. After signing in,
change the office password under **Profile**, then delete `MTO_ADMIN_PASSWORD`
from `.env` and run `php artisan config:cache` again.

### 5.4 Check it

```
php artisan visitdasol:check-production
```

It checks everything above and prints what to fix. It must end with
**"Ready to go live"** (warnings are worth reading; failures must be fixed).

### 5.5 Updating later

```
php artisan down
(copy the new files)
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up
```

---

## Part 6. Backups

Two things hold all of Visit Dasol's data: **the database** and the **uploaded
files** in `storage\app`. Back up both, together, regularly.

On XAMPP:

```
C:\xampp\mysql\bin\mysqldump -u root visitdasol > visitdasol-backup.sql
```

and copy the whole `storage\app` folder next to it.

To restore:

```
C:\xampp\mysql\bin\mysql -u root visitdasol < visitdasol-backup.sql
```

and copy `storage\app` back. A backup that has never been restored is only a hope:
try a restore on a spare copy at least once.
