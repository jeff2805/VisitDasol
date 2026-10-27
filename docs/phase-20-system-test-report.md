# Visit Dasol: Phase 20 system test report

Copy the contents of `visitdasol-phase20` over your project, replacing existing files,
then run:

```bash
php artisan config:clear
php artisan view:clear
php artisan test
```

Expected: **403 tests pass.**

---

## 1. Results at a glance

| Test type | What was done | Result |
|---|---|---|
| Automated suite | 403 tests, 2,713 checks | **All pass** |
| Full lifecycle (automated) | One continuous story across all three roles, through the real forms | **Pass** (53 checks) |
| Full lifecycle (browser) | The same story clicked through in Chromium, with real uploads and a map pin | **11 of 11 steps pass**, no script errors |
| Concurrency | 8 guests submit for the last room at the same instant, on 8 parallel server workers | **Exactly 1 booked**, 7 refused politely |
| Edge cases | Deactivation mid-session, listing offline mid-booking, expired form, Back after sign-out | **4 of 4 pass** after one fix |
| Performance | 47 resorts, 216 room types, 3,006 bookings, 5,018 log entries | **Every page under 70 ms** after two fixes |
| Query growth | 19 list pages measured at two data sizes | **No page grows** after two fixes |
| Accessibility (Phase 18) | axe-core WCAG 2 A/AA on 26 pages at 2 widths | **0 violations** |
| Security (Phase 19) | Scans, route audit, live CSRF and headers, CSP on 21 pages | **All pass**, findings fixed |

## 2. Defects found in this phase

System testing is only useful if it finds things. These four were found here, fixed,
and each is now guarded by a test so it cannot come back.

| # | Defect | How it was found | Fix | Guarded by |
|---|---|---|---|---|
| D1 | **Back button showed private data after sign-out.** On a shared computer, the next person could see the previous guest's reservations and phone number. | Browser edge-case test E4 | Signed-in pages now tell the browser never to store them (`Cache-Control: no-store`) | `test_signed_in_pages_are_never_kept_by_the_browser` |
| D2 | **Owner rooms page ran 3 extra queries per room type.** | Query measurement at two data sizes | Committed units for all room types in one query | `QueryBudgetTest` |
| D3 | **Owner availability ran 1 extra query per room type.** | Same | All reservations for the month in one query | `QueryBudgetTest` |
| D4 | **Owner rooms took 632 ms for a busy resort.** Finding the busiest night checked every reservation on every night (about 126,000 checks). | Timing with the large dataset | A single sorted pass over check-ins and check-outs; now 41 ms | `test_the_fast_calculation_matches_counting_night_by_night` checks it against the old method on 150 random stays |

D4 changed core availability logic, so it was checked three ways: the 9 existing
availability tests, a new test comparing it with the plain night-by-night count on 40
random date ranges, and the 133 booking, room, and reservation tests.

## 3. Automated test suite

| Area | Tests | Covers |
|---|---:|---|
| Tourism office (Admin) | 89 | approvals, resorts, destinations, content, bookings, reports, activity log |
| Accounts (Auth) | 73 | sign-in, registration, roles, profiles, password reset |
| Resort owners | 60 | resort listing, photos, rooms, reservations, availability |
| Database and rules | 54 | models, relationships, status rules, availability arithmetic |
| Booking engine | 47 | availability, pricing, double-booking, cancellation |
| Public site | 19 | listings, filters, visibility of unpublished records |
| Status rules (unit) | 16 | every allowed and forbidden status change |
| Reports | 15 | every figure, date clipping, CSV export |
| Security | 11 | headers, CSP, no unsafe templates, error pages |
| Map | 10 | only published pins, coordinates from the database, escaping |
| Interface consistency | 4 | no placeholders, skip links, page titles |
| System | 3 | the full lifecycle and query budgets |
| Framework samples | 2 | |
| **Total** | **403** | **2,713 checks** |

## 4. End-to-end journey in the browser

The whole lifecycle, clicked through in Chromium as each person would do it.

| Step | Who | What happens | Result |
|---|---|---|---|
| 1 | Owner | Applies, uploading a business permit PDF | Pass |
| 2 | Owner | Tries to sign in before approval: refused | Pass |
| 3 | Office | Reviews the application and approves | Pass |
| 4 | Owner | Registers the resort, clicks the map to place the pin, uploads a cover photo | Pass |
| 5 | Owner | Adds a room type (₱2,400, 2 units) | Pass |
| 6 | Public | Resort hidden until the office approves it; then listed and on the map | Pass |
| 7 | Tourist | Registers and is signed in | Pass |
| 8 | Tourist | Checks dates, books 2 nights; summary shows ₱4,800 | Pass |
| 9 | Owner | Confirms the request | Pass |
| 10 | Tourist | Sees "Confirmed" with instructions | Pass |
| 11 | Office | Finds the booking; the audit trail shows the application, approval, and confirmation | Pass |

## 5. Concurrency test

**Question:** if several guests book the last room at the same moment, can it be sold twice?

**Method.** PHP's server ran with 8 worker processes so requests were handled truly
in parallel. A room type with one unit was created. Eight separate browser sessions
signed in, opened the booking form (each seeing the room as free), and pressed
"Send request" at the same instant.

**Result.** Exactly **one** booking was created. The other seven saw:
*"Those dates have just been taken for this room type. Please choose other dates."*
The database held exactly one reservation for that room and those dates.

**Why it works.** The booking engine locks the room type's row while it writes the
reservation, then re-checks availability inside the lock (Phase 11). Each request
waits its turn, and every request after the first finds the room taken.

## 6. Edge cases

| # | Situation | Expected | Result |
|---|---|---|---|
| E1 | The office deactivates a tourist who is signed in | Signed out on their next click, with an explanation | Pass |
| E2 | A resort is taken offline while a guest has its booking form open | Submission refused; nothing created | Pass |
| E3 | A form is left open until the session expires | Friendly "This form expired" page with a safe way back | Pass |
| E4 | A guest signs out on a shared computer; the next person presses Back | The private page is not shown | **Failed, then fixed (D1)** |

## 7. Performance

**Dataset:** 47 resorts, 216 room types, 3,006 reservations (1,043 at one resort),
358 accounts, 5,018 log entries. Times are the server's response, the median of
three requests after one warm-up, on PHP's built-in development server without
opcache. Apache with opcache (Phase 21) will be faster.

| Page | Before fixes | After |
|---|---:|---:|
| Home | 41 ms | 39 ms |
| Resort list | 23 ms | 21 ms |
| Resort page with availability search | 30 ms | 30 ms |
| Map | 19 ms | 18 ms |
| Office dashboard | 24 ms | 21 ms |
| Office bookings | 27 ms | 21 ms |
| Office reports (12 months) | 56 ms | 55 ms |
| Office activity log | 31 ms | 30 ms |
| Owner reservations | 26 ms | 26 ms |
| Owner availability | 64 ms | 69 ms |
| Owner statistics | 35 ms | 36 ms |
| **Owner rooms** | **632 ms** | **41 ms** |

## 8. Limits of this testing

Stating what was *not* verified is part of an honest report.

- **Browser:** Chromium only. Bootstrap 5 supports current Firefox, Safari, and Edge,
  but they were not tested directly.
- **Server:** PHP's built-in server on Linux, not Apache on Windows (XAMPP). The
  application code is the same; Phase 21 covers the XAMPP setup.
- **Map tiles:** the test environment could not reach OpenStreetMap, so pins, popups,
  and layers were verified but not the map imagery itself.
- **Email:** reset emails were verified through the log file, not sent through a
  real mail server.
- **Load:** the concurrency test used 8 simultaneous users. Sustained heavy traffic
  was not tested; municipal-scale use is well below it.

---

## 9. User acceptance test script

For your panel, or for staff of the Municipal Tourism Office trying the system.
Run it on a freshly seeded database (`php artisan migrate:fresh --seed`).
Demo accounts use the password in `DEMO_ACCOUNT_PASSWORD`.

**Tester:** ______________________ **Date:** ______________

### A. Visitor (not signed in)

| # | Do this | Expected | Pass / Fail |
|---|---|---|---|
| A1 | Open the home page | Featured resorts, destinations, and announcements | |
| A2 | Open **Resorts**, filter by a barangay | Only resorts in that barangay | |
| A3 | Open a resort, enter dates and guests, press **Check** | Each room shows how many are free | |
| A4 | Open **Map**, click a pin | Popup with name and **View details** | |
| A5 | Open **Destinations** and one destination | Details, photos, and a small map | |

### B. Tourist (`tourist.bea@demo.visitdasol.test`)

| # | Do this | Expected | Pass / Fail |
|---|---|---|---|
| B1 | Sign in | Tourist dashboard | |
| B2 | Book a room for future dates | Reference shown, status "Awaiting confirmation" | |
| B3 | Open **My reservations**, then **View** | Stay, price, resort contact, map | |
| B4 | Cancel a reservation | Status becomes Cancelled | |
| B5 | Sign out, press the browser's Back button | Sign-in page, not the reservations | |

### C. Resort owner (`owner.saltbreeze@demo.visitdasol.test`)

| # | Do this | Expected | Pass / Fail |
|---|---|---|---|
| C1 | Sign in | Dashboard with waiting requests | |
| C2 | Open **Reservations**, open a waiting one, **Confirm** | Guest sees it confirmed | |
| C3 | **Decline** another with a reason | Dates free again; guest sees the reason | |
| C4 | Open **Availability** | Month grid of rooms free per night | |
| C5 | Add a room type, then stop bookings for it | Hidden from the public resort page | |
| C6 | Open **Statistics** | Figures and charts for this resort only | |

### D. Tourism office (`mto@visitdasol.test`)

| # | Do this | Expected | Pass / Fail |
|---|---|---|---|
| D1 | Approve the waiting owner application | Owner can now sign in | |
| D2 | Review the waiting resort; note the checklist | Approval blocked until a cover photo exists | |
| D3 | Take a resort offline with a reason | Disappears from the public site | |
| D4 | Add a destination with a map pin | Appears on `/destinations` and the map | |
| D5 | Write, then publish an announcement | Appears on the home page | |
| D6 | Open **Reports**, download the CSV | Figures, charts, and a spreadsheet file | |
| D7 | Open **Activity log** | Every action above is listed with who did it | |

### E. Security checks anyone can do

| # | Do this | Expected | Pass / Fail |
|---|---|---|---|
| E1 | As a tourist, type `/admin/dashboard` in the address bar | "Access denied" page | |
| E2 | As an owner, change the reference in a reservation's address to another resort's | "Access denied" page | |
| E3 | Sign in with a wrong password 6 times | Asked to wait before trying again | |
| E4 | Use **Forgot your password?** with an unregistered email | Same message as for a registered one | |
