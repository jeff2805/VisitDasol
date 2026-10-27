# Visit Dasol: demo script for the defense

A 20-minute walkthrough that shows every part of the system in the order a real
season would unfold, with what to say, what can go wrong, and the questions a
panel is likely to ask.

---

## The day before

- [ ] Run `php artisan visitdasol:demo-reset` and sign in once as each role.
- [ ] Run `php artisan test` and keep the result on screen or as a screenshot:
      **412 tests passing** is a strong opening slide.
- [ ] **Add real photos.** The demo resorts show placeholders. Upload a few photos
      of Dasol that you took yourself, or have permission to use, to the three
      public resorts and two destinations. It changes how the whole demo feels.
- [ ] Check the map shows its pictures (needs internet). If the venue has none,
      rehearse the offline explanation below.
- [ ] Set the laptop to never sleep, turn off notifications, and charge it.
- [ ] Set browser zoom to 110–125% so the projector audience can read.
- [ ] Open **three separate browser windows**, one per role, so you never sign
      out mid-demo: a normal Chrome window (office), a Chrome Incognito window
      (owner), and Edge (tourist). Sessions don't mix between them.
- [ ] Write the three demo sign-ins on a card.
- [ ] Optional: register one extra owner with a PDF permit, so you can show the
      office viewing an uploaded permit in section 3.

---

## The walkthrough (about 20 minutes)

### 1. The problem (1 minute)

Say: Tourism information for Dasol is scattered, resort bookings happen by phone and
Facebook messages, and the Municipal Tourism Office has no reliable view of what is
listed, where, or how busy the season is. Visit Dasol gives visitors one trusted
place to find and book, gives resorts a way to take reservations, and gives the
office oversight: nothing is published without its approval.

### 2. What a visitor sees (2 minutes). Tourist window, not signed in

1. Home page: featured resorts, destinations, announcements.
2. **Resorts**: filter by barangay and price.
3. Open a resort, enter dates, press **Check**: each room type shows how many are free.
4. **Map**: resorts and destinations as pins; click one.

Say: Everything here is public, but only listings the tourism office has approved.

### 3. The double approval (3 minutes)

1. **Office window** → Owner applications → open the waiting one (`owner.pending`).
   Show the business details, then **Approve**.
   The demo applicant has no permit file. To show the permit viewer, register a new
   owner with any PDF before the defense and approve that one instead.
2. Explain the second gate: an approved owner still cannot publish a resort. The
   office reviews each listing separately.
3. **Resort applications** → Coral Point (Demo). Point at the **checklist**: approval
   is blocked because there is no cover photo. Say that this is enforced on the
   server, not just hidden on the screen.

Say: Two gates, because approving a business and approving a listing are different
decisions. A legitimate owner can still pin their resort in the wrong barangay.

### 4. A resort owner's day (3 minutes). Owner window, `owner.saltbreeze`

1. Dashboard: requests waiting, next stays.
2. **Rooms**: rates, capacity, number of units.
3. **Reservations**: open the waiting one, **Confirm**.
4. **Availability**: the month grid of rooms free per night.

### 5. Booking (3 minutes). Tourist window, sign in as `tourist.bea`

1. Open Salt Breeze, pick dates, **Check**, **Request this room**.
2. Point at the summary: the price is worked out on the server.
3. Send the request and show the reference, e.g. `VD-20261103-5Q35B`, which
   carries the arrival date.
4. **Owner window**: the new request appears; **Confirm** it.
5. **Tourist window**: refresh; it now says Confirmed.

Say: Nothing about price or availability comes from the form. The server reads the
rate, works out the total, and checks availability again at the moment of saving.

### 6. The office's view (2 minutes). Office window

1. **Reports**: headline figures, five charts, **Download CSV**, **Print**.
2. **Bookings**: every reservation, read-only on purpose.
3. **Activity log**: everything you just did, with who did it and when.

Say: The office can see everything but cannot confirm or cancel on a resort's
behalf. Responsibility stays where it belongs, and the log shows exactly who did what.

### 7. How it was tested (3 minutes)

Show the test run, then pick two or three of these:

- **Double-booking:** eight guests pressed "Send request" for the last room at the
  same instant, on eight parallel server processes. Exactly one got it; seven were
  told politely. The database row is locked while each booking is saved.
- **Security:** a Content Security Policy means injected scripts cannot run; every
  admin, owner, and tourist route checks role and account status; password reset
  never reveals whether an email is registered.
- **A real bug found in testing:** pressing Back after signing out showed the
  previous guest's reservations, a problem on shared computers such as internet
  cafés. It is fixed, and a test keeps it fixed.
- **Performance:** with 3,000 bookings, every page answers in under 70 ms.
- **Accessibility:** zero WCAG violations on 26 pages, on desktop and phone.

### 8. Close (1 minute)

Summarise: three roles, two approval gates, a booking engine that cannot sell a
room twice, a map, reports for the office, and 412 automated tests. Then name the
limitations yourself (below) before the panel does.

---

## If something goes wrong

| Problem | What to do |
|---|---|
| Map shows grey squares | No internet. Say: "the map pictures come from OpenStreetMap; the pins, popups, and list all come from our own database and still work." Click a pin to prove it |
| Something in the data looks wrong | `php artisan visitdasol:demo-reset --force` takes seconds |
| "This page has expired" | The form sat open too long. Press **Go back** and submit again |
| A page errors | Stay calm and switch to the next section. Check `storage\logs\laravel.log` afterwards |
| Projector cuts off the page | Zoom out one step (`Ctrl` and `-`) |
| Wi-Fi blocks the panel's phones | Use your phone's hotspot (see `phase-21-deployment.md`, Part 3) |

---

## Questions the panel may ask

**Why Laravel?**
It gives tested building blocks for the parts that are easy to get wrong
(authentication, password resets, CSRF protection, database migrations), so the
project's effort went into the tourism and booking rules. It also runs on XAMPP,
which the municipality can maintain.

**How do you stop a room being booked twice?**
Availability is never stored as a counter. Each time, it is calculated from the
reservations that hold a room, night by night. When a booking is saved, the room
type's row is locked, availability is checked again inside the lock, and only then
is the reservation written. The concurrency test showed one success out of eight
simultaneous attempts.

**Why is there no online payment?**
Payment integration needs a merchant account, fees, and handling of refunds and
chargebacks, and most Dasol resorts already collect payment directly. The system
says clearly that it takes no payment. It is listed as future work.

**What if an owner puts their resort in the wrong place?**
The map pin must fall inside Dasol's boundaries, checked on the server. The office
sees the pin on a map during review, can refuse with a reason, and can correct the
details itself.

**How do you protect personal data?**
The system collects names, contact numbers, and emails, which the Data Privacy Act
of 2012 (Republic Act No. 10173) covers. Each role sees only what it needs: guests
see their own reservations, owners see only their own guests, and the office's
booking view is read-only. Passwords are hashed, pages for signed-in users are not
cached by the browser, and every important action is logged. Before real use, the
municipality should publish a privacy notice and name a data protection officer.

**Can it handle a busy season?**
It was tested with 47 resorts and 3,006 bookings: every page answered in under 70 ms,
and a test fails if any list page starts running more database queries as data grows.

**What are the limitations?**
Say these yourself:
- No online payment, and no SMS notifications.
- One resort per owner account.
- Occupancy uses the current number of rooms, because inventory changes are not
  kept as history.
- The map pictures need internet.
- Tested in Chromium; other browsers are supported by Bootstrap but not tested
  directly.

**What would you add next?**
SMS confirmations (many visitors don't check email), guest reviews, online
payment through a Philippine gateway, several resorts per owner, and an offline
map for areas with weak signal.
