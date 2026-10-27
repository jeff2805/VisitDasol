# Visit Dasol: Phase 15 reports and analytics

Copy the contents of `visitdasol-phase15` over your project, replacing existing files.

## 1. Refresh caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

No migrations are needed. Chart.js is included in the zip at
`public/vendor/chartjs/`, so nothing is downloaded at run time.

## 2. Run the tests

```bash
php artisan test
```

Expected: 351 tests pass.

## 3. Try it as the tourism office

Sign in as `mto@visitdasol.test` and open **Reports**.

- The period defaults to this month and the eleven before it. Change the dates, pick one resort, or switch **Dates refer to** between the stay and when the request was made.
- Headline figures: reservations, confirmed stays, guests, room-nights, estimated value, occupancy, how many fell through, and how many are still waiting for a reply.
- Charts: reservations and guests per month, how reservations ended, busiest resorts, where visitors come from, and new accounts per month.
- **Download CSV** gives the same filtered reservations as a spreadsheet.
- **Print** produces a clean page with no sidebar or buttons, suitable for a report to the municipal office.

## 4. Try it as a resort owner

Sign in as an owner and open **Statistics**: the same figures for their resort only, with buttons for 3, 6, 12, or 24 months.

## How the figures are worked out

- **Confirmed stays, guests, and value** count only confirmed and completed reservations. Cancelled and declined ones are counted separately as "fell through".
- **Room-nights** are clipped to the period: a stay from 29 May to 3 June contributes 2 nights to June, not 5. A booking of 3 rooms for 3 nights counts as 9.
- **Occupancy** is booked room-nights divided by the rooms on offer for the period.
- **Estimated value** is what resorts expect to collect at their listed rates. Visit Dasol takes no payment, so it is never a record of money received. Both pages say so.
- Months with nothing in them still appear on the charts, so gaps are visible rather than hidden.

## One limitation to note in your write-up

Occupancy uses each room type's **current** number of units, because the system
does not keep a history of inventory changes. If an owner adds rooms in June,
the occupancy figure for May is recalculated against the new total. For a
municipal tourism report this is a good guide, not an audited figure.

## What is protected

- Reports are office-only; owners and tourists get 403 on both the page and the CSV.
- An owner's statistics are always scoped to their own resort, whatever the address says.
- Dates from the address are cleaned: nonsense values fall back to the default period, reversed dates are swapped, and ranges longer than three years are trimmed.
- Every figure is counted by the database with aggregate queries, so the page stays fast as reservations pile up.
