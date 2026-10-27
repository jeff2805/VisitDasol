# Visit Dasol: Phase 2 database foundation

Copy the contents of `visitdasol-phase2` over your Phase 1 project, allowing it to
replace existing files. Then follow these steps.

## 1. Add the new .env settings

Copy these lines from `.env.example` into `.env` and fill in the two passwords:

```
MTO_ADMIN_FIRST_NAME=Tourism
MTO_ADMIN_LAST_NAME=Office
MTO_ADMIN_EMAIL=mto@visitdasol.test
MTO_ADMIN_PHONE=
MTO_ADMIN_PASSWORD=

SEED_DEMO_DATA=true
DEMO_ACCOUNT_PASSWORD=
```

- `MTO_ADMIN_PASSWORD`: at least 12 characters with uppercase and lowercase letters and a number.
- `DEMO_ACCOUNT_PASSWORD`: at least 8 characters. Every demo account uses it.
- Never commit `.env`. On a shared or public server, set `SEED_DEMO_DATA=false`.

## 2. Create the test database

In phpMyAdmin's SQL tab:

```sql
CREATE DATABASE visitdasol_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Tests use this database and wipe it on every run. Your real data in `visitdasol` is never touched.

## 3. Rebuild the database

```bash
php artisan config:clear
php artisan migrate:fresh --seed
```

Use `migrate:fresh`, not `migrate`. Phase 2 replaces Laravel's original `users`
migration, which your Phase 1 database already recorded as done, so plain
`migrate` would keep the old table. `migrate:fresh` deletes all tables; that is
safe now because there is no real data yet.

The seeder prints a table of demo accounts when it finishes.

## 4. Run the tests

```bash
php artisan test
```

Expected: 63 tests pass.

## 5. Check in the browser

Open `/_dev/setup`. The new "Database schema and seed data" section should show
14 tables, 1 MTO account, 17 amenities, 7 categories, and 12 demo accounts.

## 6. Optional: explore with Tinker

```bash
php artisan tinker
```

```php
App\Models\Resort::publiclyVisible()->pluck('name');
// 3 names: Bayside Kubo, Mangrove View, Salt Breeze (the pending and hidden resorts are excluded)

App\Models\Room::bookable()->count();
// 7

App\Models\User::pendingOwners()->pluck('email');
// owner.pending@demo.visitdasol.test

$owner = App\Models\User::where('email', 'owner.saltbreeze@demo.visitdasol.test')->first();
$owner->resort->rooms->pluck('name');
$owner->resort->amenities->pluck('name');

// Mass-assignment protection (throws MassAssignmentException):
new App\Models\User(['first_name' => 'X', 'role' => 'super_admin']);
```

Type `exit` to leave Tinker.

## Demo accounts (all DEMO DATA)

| Email | Role | Status | Purpose |
|---|---|---|---|
| owner.saltbreeze@demo.visitdasol.test | Owner | Active | Featured active resort |
| owner.bayside@demo.visitdasol.test | Owner | Active | Active resort |
| owner.mangrove@demo.visitdasol.test | Owner | Active | Has an inactive room type |
| owner.coralpoint@demo.visitdasol.test | Owner | Active | Resort pending review |
| owner.pending@demo.visitdasol.test | Owner | Pending | Application under review |
| owner.rejected@demo.visitdasol.test | Owner | Rejected | Rejected application |
| owner.inactive@demo.visitdasol.test | Owner | Inactive | Active resort hidden from the public |
| tourist.bea / kevin / grace / mark @demo.visitdasol.test | Tourist | Active | Booking tests (Phase 11) |
| tourist.inactive@demo.visitdasol.test | Tourist | Inactive | Refused sign-in |

Sign-in itself arrives in Phase 3.

## Troubleshooting

| Message | Fix |
|---|---|
| Set MTO_ADMIN_PASSWORD in .env | Add a password, then `php artisan config:clear` |
| MTO_ADMIN_PASSWORD is not strong enough | 12+ characters, upper- and lowercase letters, a number |
| already belongs to a non-MTO account | Use a different `MTO_ADMIN_EMAIL` |
| Set DEMO_ACCOUNT_PASSWORD | Add one (8+ characters) or set `SEED_DEMO_DATA=false` |
| Unknown column 'role' / 'first_name' | You ran `migrate` instead of `migrate:fresh --seed` |
| Tests: Unknown database 'visitdasol_test' | Step 2 was skipped |
| Changed .env but nothing happened | `php artisan config:clear` |
| MassAssignmentException or LazyLoadingViolationException | Intentional in development: a bug was caught. See Phase 2 notes on strict mode. |
