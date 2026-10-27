# Visit Dasol: Phase 10 interactive map

Copy the contents of `visitdasol-phase10` over your project, replacing existing files.

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

Expected: 265 tests pass.

## 3. Try the map

**Public map** at `/map` (the "Map" link in the navigation is now live):

- Teal pins are resorts, mango pins are destinations.
- Click a pin for a photo, the name, the barangay, and a **View details** link.
- Use the checkboxes in the top right to hide either layer.
- Click an entry in the list beside the map: the map pans to it and opens its pin.
- Resort and destination pages now include a small map with a **Get directions** link.

**Office map** at `/admin/map`:

- Shows every resort and destination, including ones visitors cannot see.
- Anything not published is greyed out, so a pending listing or a hidden destination stands out.
- Clicking through takes you to that record's office page.

## What is protected

- Coordinates always come from the database and are embedded in the page. Nothing is hard-coded in `public/js/map.js`, and a test checks that.
- The public map only ever includes approved resorts (with an active owner) and published destinations. Everything else is absent from the page source, not just hidden on screen.
- Pin popups are built from text nodes, so a resort name containing `<script>` is shown as text and never runs.
- The office map is office-only: owners and tourists get 403.

## Notes

- **Map tiles need an internet connection.** Without one the map area stays blank, but pins, popups, layer toggles, and the list all still work. The attribution line "© OpenStreetMap contributors" is required and must stay.
- My test environment blocks OpenStreetMap, so I verified pins, popups, layers, and responsiveness, but not the tile imagery itself. Tiles should appear normally on your machine.
- Pin accuracy depends on what owners and the office set. The page says so, so visitors treat pins as a guide.
