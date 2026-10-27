# Visit Dasol: Phase 7 resort approval

Copy the contents of `visitdasol-phase7` over your project, replacing existing files.

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

Expected: 207 tests pass.

## 3. Walk the approval workflow

Sign in as the tourism office (`mto@visitdasol.test`).

1. **Resort applications** in the sidebar shows a count badge. Open it.
2. Review **Coral Point Resort (Demo)**. You see the listing, its photos, a map of the pin, the owner's details, the rooms, and a checklist.
3. The demo listing has no cover photo, so **Approve and publish** is switched off and the reason is shown. Pressing the approve URL directly still fails: the checklist is enforced on the server.
4. Sign in as `owner.coralpoint@demo.visitdasol.test`, add a cover photo on **My resort**, then sign back in as the office.
5. Approve it. The listing appears at once on `/resorts`, and its rooms become bookable.
6. Open **Resorts**, choose that listing, and **Take offline** with a reason. It disappears from the public site, and the owner sees your reason on their dashboard.
7. Put it back online, and try **Feature on the home page**: featured listings are shown first.

Also try **Send back** on a pending listing with notes. The owner sees the notes, fixes the listing, and uses **Send for review again**, which puts it back in your queue.

## What the checklist requires

Three items must pass before a listing can go public:

- the owner account is active,
- a cover photo has been uploaded,
- the map pin is inside Dasol.

Two more are advisory and do not block approval: at least one room type, and a contact number.

## What is protected

- Only pending listings can be approved or sent back; only live ones can be taken offline or featured; only offline ones can be put back online. The rules come from `ResortStatus`, not from the form.
- A listing cannot be approved while its owner account is not active, so an approved listing always has someone who can manage it.
- Taking a listing offline requires a reason, which the owner sees.
- The office edit screen corrects details only. It never changes the status, the owner, or the featured flag, even if those are posted with the form.
- Owners and tourists get 403 on every office screen, and every action is a PATCH or PUT, so pasting a URL into the address bar does nothing.
- Every decision is written to the activity log, shown in the listing's History panel.

## Notes

- Map tiles need an internet connection. Without one the map is blank but the pin still shows in the right place.
- Taking a listing offline does not touch existing reservations; it only stops new ones. Phase 12 adds the owner's reservation screens.
