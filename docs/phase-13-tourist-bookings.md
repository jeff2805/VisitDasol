# Visit Dasol: Phase 13 guest booking details and cancellation

Copy the contents of `visitdasol-phase13` over your project, replacing existing files.

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

Expected: 328 tests pass.

## 3. Try it as a guest

Sign in as `tourist.bea@demo.visitdasol.test` and open **My reservations**.

1. Press **View** on a reservation. The page leads with the booking reference and status.
2. Each status is explained in plain words: waiting for the resort, confirmed (bring this reference), declined with the resort's reason, cancelled, or finished.
3. You see the stay, what you asked for, the price breakdown, the resort's phone number and address, a small map, and a **Get directions** link.
4. **Cancel this reservation** is offered while cancelling is still possible. A reason is optional.
5. After cancelling, the rooms are free for other guests straight away, and the resort sees your reason on its own screen.

Making a new booking now lands on this page, so the reference is shown immediately.

## When a guest may cancel

- While the request is **waiting** or **confirmed**, and
- up to the day before check-in.

Once the stay has started, the page says so and gives the resort's number instead. Declined, cancelled, and completed reservations are closed.

The cut-off is `booking.tourist_cancel_cutoff_days` in `config/visitdasol.php`. It is 0, meaning guests may cancel until the day before arrival. Setting it to 2, for example, would stop cancellations within two days of check-in.

## What is protected

- A reservation is only ever visible to the guest who made it, the resort's owner, and the tourism office. Another guest's reference gives 403, even though references appear in the address.
- The numeric database id never appears in any address.
- Cancelling is a PATCH request and is checked against the policy, so a pasted URL does nothing.
- Cancelling frees the rooms immediately, which the tests confirm through the availability service.
- Every cancellation is written to the audit trail with who did it and why.
