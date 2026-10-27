# Visit Dasol: Phase 12 owner reservation management

Copy the contents of `visitdasol-phase12` over your project, replacing existing files.

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

Expected: 312 tests pass.

## 3. Try it as a resort owner

Sign in as `owner.saltbreeze@demo.visitdasol.test`.

1. The dashboard now shows **requests waiting for you** and your **next confirmed stays**.
2. Open **Reservations**. Requests still waiting are listed first. Filter by status, room type, dates, or search by reference or guest name.
3. Open a reservation. You see the stay, the guest's contact details, anything they asked for, and the amount.
4. **Confirm** it, or **Decline** with a reason the guest will read. Declining frees the dates immediately.
5. After a guest checks out, **Mark completed**. Before that, the button is switched off.
6. **Availability** shows a month grid: how many rooms of each type are free on each night. Use the arrows to move between months.

Sign in as `tourist.kevin@demo.visitdasol.test` afterwards: the reservation you confirmed now shows as confirmed under **My reservations**.

## The rules behind the buttons

| Action | Allowed when |
|---|---|
| Confirm | The request is waiting **and** the arrival date has not passed |
| Decline | The request is waiting (a reason is required) |
| Mark completed | The stay is confirmed **and** the check-out date has passed |
| Cancel a confirmed stay | Before the guest arrives (a reason is required) |

Rejected, cancelled, and completed reservations are final; nothing can move them again. An unanswered request whose arrival date has passed can only be declined, and it stops holding the room automatically.

## What is protected

- Every list uses `Booking::forOwner()`, and every action checks the reservation's resort against the resorts this owner has. Another resort's reference gives 403.
- Statuses only ever move along the allowed path, checked in `BookingStatus` and again in `BookingStatusManager`, so no button or URL can jump a step.
- Declining and cancelling require a reason, which the guest sees and the audit trail keeps.
- All decisions are PATCH requests, so pasting a URL into the address bar does nothing.
- Availability numbers are calculated from reservations each time; nothing is stored that could drift.

## Note

Visit Dasol takes no payment. The owner screens say so, and the guest's pages say the resort collects payment directly.
