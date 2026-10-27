# Visit Dasol: Phase 19 security review and password reset

Copy the contents of `visitdasol-phase19` over your project, replacing existing files.

## 1. Update `.env`

No new tables are needed (`password_reset_tokens` was created in Phase 2).
Check these two settings in your `.env`:

```
MAIL_MAILER=log
SESSION_SECURE_COOKIE=false
```

`.env.example` now explains both. Leave them as shown while developing on XAMPP.

## 2. Refresh caches and run the tests

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan test
```

Expected: 397 tests pass.

---

## Part A: Password reset by email

### Try it

1. On the sign-in page, press **Forgot your password?**
2. Enter a registered email, for example `tourist.grace@demo.visitdasol.test`.
3. Because `MAIL_MAILER=log`, the email is written to `storage/logs/laravel.log`
   instead of being sent. Open that file, find **Reset your Visit Dasol password**,
   and copy the link under "Choose a new password".
4. Open the link, choose a new password, and sign in with it.

### How it is protected

| Risk | Protection | Checked by |
|---|---|---|
| Finding out who has an account | The same message appears whether or not the email is registered | `test_an_unknown_address_gets_exactly_the_same_answer` |
| Guessing a reset token | Tokens are long random values, stored hashed, and checked against the email | `test_a_made_up_token_or_someone_elses_email_is_refused` |
| Using another person's token | A token only works with the email it was issued for | same test |
| Reusing an old link | Each link works once | `test_a_link_works_only_once`, and in the browser |
| An old link found later | Links expire after 60 minutes | `test_an_expired_link_is_refused` |
| Flooding someone's inbox | One email per address per minute, and 6 requests per minute per visitor | `test_repeated_requests_for_one_address_are_limited` |
| An attacker still signed in elsewhere | A reset signs out every other session and invalidates "remember me" | `test_a_valid_link_sets_the_new_password_and_signs_out_other_devices` |
| Reset used to bypass approval | Nobody is signed in automatically; a pending owner is still refused at sign-in | `test_resetting_does_not_let_an_unapproved_owner_in` |
| Weak new password | The same rules as registration (8+ characters, letters and numbers) | `test_the_new_password_must_meet_the_rules` |

Every reset is written to the activity log.

### Sending real email

For the live server, set these in `.env` (Gmail shown; any SMTP provider works):

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your.office.address@gmail.com
MAIL_PASSWORD=the-16-character-app-password
MAIL_FROM_ADDRESS="your.office.address@gmail.com"
MAIL_FROM_NAME="Visit Dasol"
```

Gmail requires an **app password**, created under Google Account → Security →
2-Step Verification → App passwords. The normal account password will not work.
Run `php artisan config:clear` after changing mail settings.

---

## Part B: Security review

The review was done by evidence, not by assumption. Each area below lists what
was checked, how, and the result.

### How it was checked

1. **Static scans** of every controller, service, and template for the classic
   risk patterns (unescaped output, mass assignment, raw SQL, dangerous calls).
2. **A route audit** of all 120 routes: every state-changing route must be
   non-GET, and every admin, owner, and tourist route must require sign-in, an
   active account, and the right role.
3. **An authorization audit** of every controller action in the three areas.
4. **Live checks against the running server** with `curl`: CSRF and headers.
5. **A browser check** of 21 pages across all roles under the new Content
   Security Policy, watching for blocked scripts.
6. **The full test suite**, which exercises these rules from the outside.

### Findings

| Area | Result | Evidence |
|---|---|---|
| **Cross-site scripting** | Pass. No template prints unescaped output; user text is always escaped | Scan found no `{!! !!}`; guard test; XSS tests on maps and announcements |
| **Content Security Policy** | **Added.** The browser now runs only this site's own script files | 0 violations on 21 pages; maps and charts still render |
| **Inline scripts** | **Fixed.** Two removed (print button, setup page map) so the CSP can be strict | Guard test fails if one is added |
| **SQL injection** | Pass. All queries use the query builder or bound parameters; the one interpolated value is chosen by code from two fixed column names | Scan listed every raw fragment; each reviewed |
| **Mass assignment** | Pass. No controller uses the whole request; `role` and `status` are never fillable | Scan; guard test; Phase 3 tests |
| **CSRF** | Pass. Requests without a token are refused | `curl`: `POST /login` and `PATCH …/approve` both return **419** |
| **Clickjacking** | **Added.** Pages cannot be framed by other sites | `X-Frame-Options: SAMEORIGIN` and `frame-ancestors 'self'` |
| **MIME sniffing** | **Added** | `X-Content-Type-Options: nosniff` |
| **Referrer leakage** | **Added.** Full addresses are not sent to other sites | `Referrer-Policy: strict-origin-when-cross-origin` |
| **Open redirect** | **Fixed.** The "session expired" page linked back to whatever the browser's Referer header said, which another site can set; it now only links within Visit Dasol | `test_the_expired_page_never_links_back_to_another_site` |
| **Authentication** | Pass. Passwords hashed with bcrypt; session renewed at sign-in and destroyed at sign-out; sign-in limited to 5 attempts per minute | Phase 3 tests; `test_the_login_form_is_rate_limited` |
| **Account enumeration** | Pass for sign-in and password reset | Phase 3 and Part A tests |
| **Authorization (roles)** | Pass. All 91 area routes require sign-in, an active account, and the correct role | Route audit output |
| **Authorization (ownership)** | Pass. Owners cannot see or change other resorts' rooms, photos, or reservations; guests cannot see others' reservations | IDOR tests in Phases 6, 8, 12, 13 |
| **Defence in depth** | **Improved.** The office dashboard, which lists other people's details, now also checks a policy, not only the route | Authorization audit |
| **Booking manipulation** | Pass. Price, resort, and guest are never taken from the form; availability is re-checked under a row lock | Phase 11 tests |
| **File uploads** | Pass. Images only (JPG, PNG, WEBP; SVG refused), size and dimension limits, random file names; permit documents on a private disk, served only to the office | Phase 3 and 6 tests |
| **Error pages** | Pass. With debugging off, errors show a friendly page with no technical detail | `test_errors_do_not_reveal_details_when_debug_is_off` |
| **Developer tools** | Pass. The setup page exists only when `APP_ENV=local` | `test_the_developer_setup_page_does_not_exist_outside_local` |
| **Session cookie** | Pass locally: `httponly` and `samesite=lax`. `secure` must be switched on with HTTPS (see below) | `curl` header output |
| **Audit trail** | Pass. Append-only, and secrets are stripped before writing | Phase 17 tests |

### Accepted risks

These are known and deliberately left as they are. Mention them in your write-up;
recognising limits is part of a sound security review.

1. **Registration says when an email is already registered.** This lets someone
   check whether an address has an account. It is a common trade-off: hiding it
   would leave genuine users confused about why they cannot register. Sign-in
   and password reset do not leak this.
2. **`style-src` allows inline styles.** A few templates use `style=""`
   attributes. Styles cannot run code, so the risk is low; moving them into the
   stylesheet would allow removing `'unsafe-inline'` later.
3. **Uploaded images are served publicly** by the web server, as any public
   photo would be. Only validated image types are accepted.
4. **No malware scanning of uploads.** Files are checked for type and size, not
   scanned. Acceptable for images at municipal scale.

### Before going live (carried into Phase 21)

- [ ] `APP_ENV=production` and `APP_DEBUG=false`
- [ ] Serve the site over **HTTPS**, then set `SESSION_SECURE_COOKIE=true`
- [ ] A new `APP_KEY` generated on the server, never copied from development
- [ ] A strong, unique `MTO_ADMIN_PASSWORD`, and `SEED_DEMO_DATA=false`
- [ ] Real SMTP settings so reset emails are actually sent
- [ ] Run `composer audit` to check dependencies for published vulnerabilities
- [ ] Database user with only the privileges the application needs
- [ ] Regular database and `storage/app` backups
