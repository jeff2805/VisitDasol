# Visit Dasol: Phase 6 resort registration and profile

Copy the contents of `visitdasol-phase6` over your project, replacing existing files.

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

Expected: 190 tests pass.

## 3. Try it as a resort owner

Sign in as `owner.pending@demo.visitdasol.test`: sign-in is still refused, because the
account is waiting for review. That is the point of the workflow.

Now sign in as an approved owner.

**An owner with no resort** (`owner.saltbreeze@...` already has one, so to see this
screen, sign in as the office, approve the pending application, then sign in as that
owner):

1. The dashboard shows "Register my resort".
2. Fill in the form. Click the map to drop your pin; the two coordinate boxes fill in.
3. Save. The listing is created as **pending** and does not appear publicly.

**An owner with a resort** (`owner.saltbreeze@demo.visitdasol.test`):

- **My resort** shows the status banner, details, amenities, photos, and rooms.
- **Edit details** changes anything, including moving the map pin.
- **Photos**: upload several at once, set one as the cover, or remove one.
- The demo owner `owner.coralpoint@...` shows the "waiting for review" banner.

**A rejected listing:** as the office, you cannot reject resorts yet (that is Phase 7).
To see the flow now, set that resort's `status` to `rejected` and write something in
`review_remarks` in phpMyAdmin. The owner then sees the reason, can edit, and has a
**Send for review again** button that puts it back to pending.

## What is protected

- No resort id appears in any owner URL. The resort is always looked up from the signed-in owner, so one owner cannot reach another's listing by changing the address.
- Photo actions check the photo's parent resort, so an owner cannot delete or reuse someone else's photo by putting its id in the URL.
- A form that posts `status`, `owner_id`, or `is_featured` is ignored: those are set by the server.
- New resorts are always pending, and editing never changes the status. Only a rejected listing can be resubmitted.
- Owners can never approve or feature their own resort.
- Uploads must be real JPG, PNG, or WEBP images of at least 400x300 pixels and up to 3 MB. `.php` and `.svg` files are refused. Laravel renames every file, so the original name never appears in a URL.
- The gallery limit of 10 photos counts what is already stored, so it cannot be passed by uploading in several goes.
- The map pin must be inside the Dasol boundary box, checked on the server as well as on the map.

## Notes

- Map tiles need an internet connection. Without one, the map is blank but still works: clicking still sets the coordinates, and you can type them instead.
- The boundary box in `config/visitdasol.php` is deliberately generous and approximate. Tighten `map.bounds` if you want stricter pin placement.
- Changes to a **live** listing take effect at once and are written to the activity log for the office.
