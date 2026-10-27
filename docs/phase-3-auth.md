# Visit Dasol: Phase 3 authentication and roles

Copy the contents of `visitdasol-phase3` over your project, replacing existing files.

## 1. Delete one file from Phase 1

The sign-in layout preview is replaced by the real sign-in page:

```powershell
Remove-Item resources\views\dev\preview-auth.blade.php
```

## 2. Refresh caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

No migrations are needed; Phase 3 adds no database columns.

## 3. Run the tests

```bash
php artisan test
```

Expected: 124 tests pass.

## 4. Try it in the browser

Demo passwords come from your `.env`: `DEMO_ACCOUNT_PASSWORD` for demo accounts,
`MTO_ADMIN_PASSWORD` for the tourism office account.

| Sign in as | Expected |
|---|---|
| `mto@visitdasol.test` | Tourism office dashboard, with counts of applications waiting |
| `owner.saltbreeze@demo.visitdasol.test` | Owner dashboard showing that owner's resort only |
| `tourist.bea@demo.visitdasol.test` | Tourist account page |
| `owner.pending@demo.visitdasol.test` | Refused: "still being reviewed" |
| `owner.rejected@demo.visitdasol.test` | Refused, with the rejection reason |
| `owner.inactive@demo.visitdasol.test` | Refused: "has been deactivated" |
| Any account with a wrong password | "The email or password is incorrect." |

Also try these:

- **Register a tourist** at `/register`: you are signed in immediately.
- **Apply as an owner** at `/register/owner`: you are *not* signed in, and you land on a confirmation page. Signing in is refused until the office approves the account (Phase 4 adds the approval screen; for now you can approve manually in phpMyAdmin by setting that user's `status` to `active`).
- **Type another role's URL**, for example `/admin/dashboard` while signed in as a tourist: you get the 403 page.
- **Sign out**, then open `/owner/dashboard`: you are sent to sign-in with "Please sign in to continue."
- **Wrong password five times**: further attempts are blocked for a minute, even with the correct password.
- **Profile**: change your name, change your password, and try changing your email without entering your current password.

## What is protected

- `role` and `status` are never accepted from a form. A registration that tries to send `role=super_admin` still creates an ordinary account.
- The status message ("still being reviewed") appears only after the password is correct, so nobody can use it to discover which emails are registered.
- Sign-in attempts are limited to 5 per email and IP address per minute.
- Deactivating an account takes effect on that user's next request, even if they are already signed in.
- Changing a password signs out that user's other devices.
- Permit documents are stored outside the public folder; only the tourism office will be able to open them (Phase 4).

## Troubleshooting

| Symptom | Fix |
|---|---|
| Route [login] not defined | `php artisan route:clear` |
| Old pages still showing | `php artisan view:clear` |
| "Too many sign-in attempts" while testing | Wait one minute, or use a different email |
| Owner sign-in refused after approving in phpMyAdmin | Set `status` to exactly `active` |
