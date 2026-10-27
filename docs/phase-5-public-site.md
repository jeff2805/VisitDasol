# Visit Dasol: Phase 5 public tourism website

Copy the contents of `visitdasol-phase5` over your project, replacing existing files.

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

Expected: 166 tests pass.

## 3. Look around the site (no sign-in needed)

| Page | What to check |
|---|---|
| `/` | Hero, three approved resorts (featured first), destinations, latest announcements |
| `/resorts` | Filter by barangay, guests, maximum price, and amenities; sort by price |
| `/resorts/salt-breeze-beach-resort-demo` | Photos, amenities, room rates, check-in times, location link |
| `/destinations` | Category tabs and search |
| `/announcements` | Published announcements only |
| `/about` | About the municipality and how the site works |

## 4. Confirm that unapproved listings stay hidden

These demo records must **not** appear anywhere public, and opening their
address directly must return "page not found":

| Address | Why it is hidden |
|---|---|
| `/resorts/coral-point-resort-demo` | The resort is still waiting for review |
| `/resorts/hidden-cove-resort-demo` | The resort is active, but its owner account is deactivated |

Try it yourself: sign in as the tourism office, deactivate
`owner.saltbreeze@demo.visitdasol.test`, then reload `/resorts`. That resort
disappears at once. Reactivate the owner and it comes back, without the
resort's own status ever changing.

Drafts behave the same way: the demo announcement "Draft: upcoming event
details (Demo)" is not listed, and its address returns "page not found".

## What is protected

- Every public query starts from `Resort::publiclyVisible()`, `Destination::active()`, or `Announcement::published()`.
- Room rates come only from active room types in visible resorts.
- Owner-supplied text is escaped, so a resort name containing `<script>` is printed, never run.
- Internal fields (owner email, review remarks) never reach a public page.
- Filter values from the query string are cleaned before use: an unknown barangay, a negative price, or a non-numeric guest count is ignored instead of breaking the page.

## Notes

- Demo listings show a "No photo yet" placeholder because the demo data has no image files. Photo uploads arrive in Phases 6 and 8.
- "Open in map" links out to OpenStreetMap. The interactive map page arrives in Phase 10, and the Map link in the navigation stays greyed out until then.
- The About page text is deliberately short and factual. Replace the "The municipality" section with the office's own wording, and fill in the contact details in `config/visitdasol.php`.
