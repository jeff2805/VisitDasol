# Visit Dasol: Phase 16 announcements

Copy the contents of `visitdasol-phase16` over your project, replacing existing files.

## 1. Refresh caches

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

No migrations are needed. The public announcement pages were built in Phase 5;
this phase adds the office screens that fill them.

## 2. Run the tests

```bash
php artisan test
```

Expected: 363 tests pass.

## 3. Try it as the tourism office

Sign in as `mto@visitdasol.test` and open **Announcements**.

1. **Write an announcement**: title, an optional one-line summary for the home page, the text itself, and an optional picture. It is saved as a **draft**, so nothing appears to visitors yet.
2. Back on the list, press **Publish**. It appears at once on `/announcements` and on the home page.
3. **Take down** turns a published announcement back into a draft. Nothing is deleted, and it can be published again later.
4. The **pin** button keeps an announcement at the top of the public list and home page. Only something visitors can already read may be pinned.
5. **Delete** removes the announcement and its picture.

**To schedule one:** put a future date and time in *Publish date and time*, then press **Publish now**. It stays hidden until that moment arrives. The list marks it "Scheduled".

## What is protected

- Writing and publishing are separate steps. A form that posts `status` or `is_featured` is ignored, so nothing can be published by accident.
- Drafts and scheduled announcements are absent from the public pages entirely, and their addresses return "page not found".
- Taking an announcement down also unpins it, so nothing hidden can stay pinned to the home page.
- The text is plain and always shown escaped: an announcement containing `<script>` is printed as text, never run. A test checks this.
- Only the office can reach these screens; owners and tourists get 403.
- Every publish and takedown is written to the activity log.

## Note

Keep dated advisories here rather than in destination descriptions, so visitors can see when something was posted and the office can take it down cleanly afterwards.
