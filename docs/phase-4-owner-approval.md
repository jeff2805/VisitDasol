# Visit Dasol: Phase 4 owner application approval

Copy the contents of `visitdasol-phase4` over your project, replacing existing files.

## 1. Refresh caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

No migrations are needed. Phase 4 writes to the `activity_logs` table created in Phase 2.

## 2. Run the tests

```bash
php artisan test
```

Expected: 147 tests pass.

## 3. Try the workflow

Sign in as the tourism office (`mto@visitdasol.test`).

1. **Owner applications** in the sidebar shows a count badge. Open it.
2. Review **Ana Mendoza (Demo)**, the pending applicant.
3. Press **Turn down** and submit with an empty reason: the dialog reopens with the error.
4. Fill in a reason and turn the application down. Then sign in as `owner.rejected@demo.visitdasol.test`: sign-in is refused and the reason is shown.
5. Back in the office account, open the rejected tab, choose **Review again**, then **Approve application**.
6. Sign in as that owner: they now reach the owner dashboard.

Then try the account screens:

- **Users**: filter by account type and status, or search by name or email.
- Open an owner and **Deactivate** them. Their resort disappears from `Resort::publiclyVisible()` right away, while the resort's own status stays "active". Reactivating brings it back.
- **Resort owners** lists every owner with the listing they manage.

## What is protected

- Approve, turn down, reopen, activate, and deactivate are all POST-style routes (PATCH). Typing the URL in the address bar returns "method not allowed".
- Only pending applications can be approved or turned down; only rejected ones can be reopened. The transitions come from `UserStatus`, not from the form.
- The office cannot deactivate its own account or another office account.
- Tourist accounts are not owner applications: those URLs return 403.
- Permit documents are streamed by an office-only route. The files sit outside the public folder and can never be reached by URL.
- Deactivating deletes that person's sessions, so open browsers are signed out at once.
- Every decision is written to `activity_logs` with who did it, when, the old and new status, and the IP address. Passwords and tokens are stripped before writing.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Page links look unstyled | `php artisan view:clear`; Bootstrap pagination is switched on in AppServiceProvider |
| "Route [admin.users.index] not defined" | `php artisan route:clear` |
| Sidebar counts do not appear | `php artisan view:clear`; they come from a view composer |
| An approved owner still cannot sign in | Check their status is exactly `active` on the account page |
