# Visit Dasol: Phase 14 booking monitoring

Copy the contents of `visitdasol-phase14` over your project, replacing existing files.

## 1. Refresh caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

No migrations are needed.

## 2. Run the tests

```bash
php artisan test
```

Expected: 336 tests pass.

## 3. Try it as the tourism office

Sign in as `mto@visitdasol.test` and open **Bookings**.

- Status tabs across the top show how many reservations are in each state.
- Filter by resort, or search by reference, guest name, or email.
- **Dates refer to** switches the date range between the stay itself and when the request was made. The first answers "who is staying in October", the second answers "what was booked last week".
- Open a reservation to see the full record: the stay, the amount, the guest, the resort and its owner, and a timeline of what happened and when.
- The dashboard now also counts reservations, requests waiting for a reply, and upcoming stays.

## Why there are no buttons here

This area is **read only on purpose**. Resorts answer their own requests and
guests cancel their own reservations, so the office watching over the system
cannot quietly confirm or cancel on their behalf. That keeps responsibility
where it belongs and keeps the audit trail honest.

This is enforced, not just hidden: signed in as the office, the owner and
guest actions return 403, which the tests check.

## What is protected

- Owners and tourists get 403 on every screen here, and guests are sent to sign in.
- Filter values from the address are cleaned before use: an unknown status, a non-date, or a SQL fragment is ignored and the page still works.
- Reservations are addressed by reference, never by database id.
