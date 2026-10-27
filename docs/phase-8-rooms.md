# Visit Dasol: Phase 8 room management

Copy the contents of `visitdasol-phase8` over your project, replacing existing files.

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

Expected: 234 tests pass.

## 3. Try it as a resort owner

Sign in as `owner.mangrove@demo.visitdasol.test` and open **Rooms**.

- **Add room type**: name, category, rate per night, guests per room, and how many rooms of that kind you have.
- **Edit** any room type, or upload up to 5 photos for it. Photos appear on the public resort page.
- **Stop bookings** hides a room type from guests while keeping it in your list. **Allow bookings** puts it back. The demo "Loft Room" starts switched off.
- **Delete** removes a room type, but only while it has no reservations.

Check the public side afterwards: open `/resorts/mangrove-view-inn-demo`. Only room types that allow bookings are listed, with their rates and photos.

## How availability is counted

Guests book a **room type**, not a specific room, so "how many rooms of this
type you have" is what availability is worked out from.

Units in use are counted **night by night**, and the busiest night decides.
This matters because two bookings can both overlap your date range without
overlapping each other:

```
2 units.   A: Oct 10-12    B: Oct 12-14
Adding them up would say 2 units are taken for Oct 10-14.
Per night: Oct 10 = 1, Oct 11 = 1, Oct 12 = 1, Oct 13 = 1  ->  1 unit free.
```

The same class does the work for the booking engine in Phase 11.

## What is protected

- Every room action is checked against the room's resort, so an owner cannot read or change another resort's rooms by changing the id in the URL.
- Photo deletion uses scoped route binding: the photo must belong to the room named in the URL, or the answer is "not found".
- A form that posts `resort_id` or `status` is ignored.
- Room names must be unique within one resort; different resorts may use the same names.
- **Inventory cannot drop below what guests already hold.** If two units are booked on any upcoming night, the number of units cannot be set below two. Cancelled and rejected reservations do not count, and past stays do not count.
- Room types with reservations cannot be deleted, so booking history stays intact. Owners switch them off instead.
- Uploads must be real JPG, PNG, or WEBP images; `.php` and `.svg` files are refused.

## Note

Switching a room type off does not cancel reservations guests already hold. It only stops new ones.
