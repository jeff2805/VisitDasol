# Visit Dasol

A tourism and resort booking system for the Municipality of Dasol, Pangasinan.

Visitors find resorts and destinations and request stays. Resort owners manage
their listings, rooms, and reservations. The Municipal Tourism Office approves
every owner and every listing, publishes destinations and announcements, and
monitors bookings through reports and an audit trail.

## Built with

Laravel 12 · PHP 8.2+ · MySQL / MariaDB · Bootstrap 5 · Leaflet with OpenStreetMap ·
Chart.js. No Node.js build step: every front-end library is included in `public/vendor`.

## Main features

- **Two approval gates.** Owners are approved before they can sign in, then each
  resort is reviewed against a checklist before it is published.
- **A booking engine that cannot double-book.** Availability is counted night by
  night from reservations, and each booking is saved under a database lock.
- **An interactive map** of resorts and destinations, with pins placed by owners and
  checked to be inside Dasol.
- **Reports** for the tourism office: reservations, guests, room-nights, occupancy,
  busiest resorts, visitor origins, with CSV export.
- **An audit trail** of every approval, status change, and reservation decision.
- **Security:** role checks on every route, a strict Content Security Policy,
  rate-limited sign-in, and password reset by email link.

## Quick start (XAMPP)

```
composer install
copy .env.example .env        (then set the DB_, MTO_ADMIN_ and DEMO_ values)
php artisan key:generate
php artisan migrate:fresh --seed
php artisan storage:link
```

Full instructions, including the Apache virtual host, are in
[docs/phase-21-deployment.md](docs/phase-21-deployment.md).

## Useful commands

| Command | Does |
|---|---|
| `php artisan test` | Runs the 412 automated tests |
| `php artisan visitdasol:demo-reset` | Reloads the demo data (refuses on a live site) |
| `php artisan visitdasol:check-production` | Checks a server is safe to go live |

## Documentation

See [docs/README.md](docs/README.md): installation, the defense demo script, the
security review, the system test report, and notes on how each part was built.

## Demo data

The demo accounts, resorts, and bookings are fictional and marked "(Demo)" or
"DEMO DATA". They are for testing and presentation only and must not be loaded on
a live site; `visitdasol:check-production` fails if they are present.
