# Visit Dasol: Phase 9 destinations, categories, and amenities

Copy the contents of `visitdasol-phase9` over your project, replacing existing files.

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

Expected: 255 tests pass.

## 3. Try it as the tourism office

Sign in as `mto@visitdasol.test`.

**Destinations**

1. **Add destination**: name, category, description, barangay, and a map pin (click the map).
2. It is published as soon as you save, so it appears at once on `/destinations`.
3. On the edit screen, upload photos and choose which one is the cover.
4. Back on the list, use **Hide** to take a destination off the public site, **Publish** to bring it back, and the star to highlight it on the home page.
5. **Delete** removes a destination and its photo files.

The six demo destinations are already there, including one hidden ("Sample Hidden Falls").

**Destination categories**

Rename a category or change its icon inline, add new ones, and delete unused ones. A category used by destinations cannot be deleted, so no destination is ever left without one.

**Amenities**

This is the list resort owners tick from. Add amenities, rename them, or change their icons.

- **Switch off** removes an amenity from the owner's form and from the public filter, but leaves it on resorts that already use it.
- **Delete** is only possible while no resort uses it.

Icon names come from Bootstrap Icons, without the `bi-` prefix (for example `wifi`, `tree`, `cup-hot`). Browse them at https://icons.getbootstrap.com.

## What is protected

- Every screen here is the office's alone: owners and tourists get 403.
- `status`, `is_featured`, `slug`, and `created_by` are set by the server; posting them with the form does nothing.
- A map pin must be inside Dasol, checked on the server as well as on the map.
- Hiding a destination also removes it from the home page highlights, so nothing hidden can stay featured.
- Photo routes are scoped: a photo must belong to the destination named in the URL, or the answer is "not found".
- Uploads must be real JPG, PNG, or WEBP images; `.php` and `.svg` are refused.
- Renaming never changes a destination's public address, so links you have already shared keep working.

## Note

Descriptions are shown to visitors as official information from the tourism office. Write only what the office can stand behind, and keep dated advisories in Announcements (Phase 16).
