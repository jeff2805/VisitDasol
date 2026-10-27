# Visit Dasol: Phase 11 booking engine

Copy the contents of `visitdasol-phase11` over your project, replacing existing files.

## 1. Refresh caches and rebuild the demo data

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan migrate:fresh --seed
```

There are no new tables. Re-seeding is what adds the demo reservations.

## 2. Run the tests

```bash
php artisan test
```

Expected: 296 tests pass.

## 3. Try booking a room

1. Open a resort, for example `/resorts/salt-breeze-beach-resort-demo`.
2. Enter a check-in and check-out date and the number of guests, then press **Check availability**. Each room type shows how many rooms are free for those dates, or "Fully booked for these dates".
3. Press **Request this room**. If you are not signed in, you are asked to sign in and are brought straight back to the same room and dates.
4. The request page shows a summary worked out on the server: the nightly rate, the number of nights, and the total.
5. Send the request. You get a reference such as **VD-20261103-5Q35B**, and the reservation appears under **My reservations** as "Awaiting confirmation".

Owners confirm or decline requests in Phase 12; cancelling and the full booking detail page arrive in Phase 13.

## How availability works

A stay occupies the nights `[check-in, check-out)`. The guest leaves on the
check-out day, so another guest may arrive that same day.

Units in use are counted night by night, and the busiest night decides. Two
bookings can both overlap your dates without overlapping each other, so
adding them together would wrongly report a room as full.

Which reservations hold a room:

| Status | Holds a room? |
|---|---|
| Confirmed, Completed | Yes |
| Pending, while the check-in date has not passed | Yes |
| Pending, once the check-in date has passed | No, it is stale |
| Cancelled, Rejected | No |

## What is protected

- **The price never comes from the form.** The rate is read from the database inside the transaction and the total is worked out in centavos, so a changed hidden field cannot alter what is charged.
- **`user_id` and `resort_id` never come from the form either.** The guest is the signed-in user; the resort is taken from the room.
- **The last room cannot be sold twice.** The room's row is locked while the reservation is written, and availability is checked again inside that lock. A form left open while someone else books is refused with an explanation.
- **A listing taken offline mid-booking is refused**, as is a room type switched off.
- **Guests cannot exceed a room's capacity**, counting the number of rooms requested.
- Dates in the past, check-out before check-in, stays beyond 30 nights, and dates more than a year ahead are all refused, in the request rules and again in the engine.
- One guest may hold at most 3 requests awaiting a reply, so nobody can tie up several resorts at once.
- Reservations are only ever visible to the guest who made them, the resort's owner, and the tourism office.
- References use an alphabet without 0/O and 1/I, so they are safe to read aloud, and the numeric database id is never shown.

## Notes

- No payment is taken. The resort confirms the request and collects payment directly, which the booking pages say plainly.
- Limits live in `config/visitdasol.php` under `booking`: `max_nights`, `max_advance_days`, and `max_pending_per_tourist`.
