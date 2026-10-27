# Visit Dasol: Phase 17 activity log

Copy the contents of `visitdasol-phase17` over your project, replacing existing files.

## 1. Refresh caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

No migrations are needed. The `activity_logs` table was created in Phase 2,
and writing to it started in Phase 4; this phase adds the screen that reads it.

## 2. Run the tests

```bash
php artisan test
```

Expected: 372 tests pass.

## 3. Try it as the tourism office

Sign in as `mto@visitdasol.test` and open **Activity log**.

Each entry shows when it happened, what happened in plain words, who did it
and in what role, what changed (for example "From: pending, To: active"), a
link to the record, and the IP address.

Filter by part of the system (owner, resort, booking, and so on), by who did
it, by date, or search the wording. The "part of the system" list only offers
groups that actually appear, so you never pick an empty filter.

## What is recorded

| Area | Examples |
|---|---|
| Owner accounts | applied, approved, rejected, reopened, activated, deactivated |
| Resorts | submitted, resubmitted, approved, rejected, activated, deactivated, featured, edited by the office |
| Rooms | created, updated, opened or closed for booking, deleted |
| Reservations | requested, confirmed, declined, cancelled, completed |
| Tourism content | destinations, categories, amenities, announcements |

Actions by owners and guests are recorded too, not only the office's, so a
reservation's whole history can be traced.

## What is protected

- **Entries are only ever added.** There is no route to edit or delete one, which a test checks by asserting those route names do not exist.
- **The log outlives its subject.** If a resort is deleted, its entries remain and the screen says "Record no longer exists".
- **Secrets are stripped before writing.** Passwords, tokens, and similar keys are removed even if a caller passes them by mistake; a test confirms this.
- Only the office can read the log. Owners and tourists get 403.

## Note on keeping the log

Entries are kept indefinitely, which is what an audit trail is for. If the
table ever grows uncomfortably large on a small server, archive old rows in
the database rather than deleting them from the application, so the trail
stays intact.
