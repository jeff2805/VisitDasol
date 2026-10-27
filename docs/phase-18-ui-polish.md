# Visit Dasol: Phase 18 UI/UX polish

Copy the contents of `visitdasol-phase18` over your project, replacing existing files.

## 1. Refresh caches

```bash
php artisan view:clear
```

No migrations or configuration changes.

## 2. Run the tests

```bash
php artisan test
```

Expected: 376 tests pass.

## How this pass was done

Rather than changing things by eye, every page was checked the same way:

1. **An automated accessibility audit** (axe-core, WCAG 2 A and AA rules) on 26 pages covering all four roles: visitor, tourist, resort owner, and tourism office.
2. **Each page at two widths**, desktop (1440 px) and phone (390 px), checking for sideways scrolling.
3. **A visual review** of the busiest screens on a phone.

The first audit found only three problems across 52 page checks. All are fixed, and the audit now reports none.

## What was fixed

| Problem | Where | Fix |
|---|---|---|
| Page scrolled sideways on phones | About, announcement pages | Bootstrap's widest gutter (`g-5`) pulls rows past the container edge on narrow screens; rows now use `g-4` on phones and `g-5` from large screens up |
| Tables that scroll sideways could not be reached with the keyboard | Admin dashboard, owner availability, and any wide table | Tables that actually overflow become focusable and are named after their section, so keyboard and screen-reader users can scroll them |
| Booking tables squeezed words onto separate lines on phones | Owner, office, and tourist reservation lists | Rows now stay on one line and the table scrolls sideways instead |
| Pages still promised features that now exist ("Available soon", "still to come") | Dashboards, resort page, home page | Dead fallbacks removed; the owner dashboard's build note is replaced with practical advice |

## Checked and already fine

- No JavaScript errors on any page.
- The phone sidebar drawer opens, lists every section, and closes with its button.
- Every layout has a working "Skip to main content" link.
- Every sidebar and navigation link is live; none are greyed out any more.
- Status colours, focus outlines, form errors, and dialogs are consistent across all areas.

## Guard tests

`tests/Feature/UiConsistencyTest.php` keeps these fixes from regressing:

- no page contains placeholder language such as "Available soon",
- no navigation link renders disabled,
- every layout has the skip link and a `main-content` target,
- page titles name the site.

## Checking accessibility yourself

The same audit can be repeated with the free **axe DevTools** browser extension:
open any page, open the browser's developer tools, choose the axe tab, and run
a scan. It should report no violations.
