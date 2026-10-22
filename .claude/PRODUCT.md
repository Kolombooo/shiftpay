# Shift Pay: product description

A personal, self-hosted tracker for hourly workers. You log shifts you have worked and shifts you have planned, add a bonus to any shift, and see what your payout for the month comes to. It is one PHP app in one Docker container, with data stored in SQLite on a volume. It works equally well on a phone and a desktop browser. You sign in once per device and stay signed in.

A working front-end prototype exists as `shift-pay.html` (single file, vanilla JS, data in localStorage). Treat it as the visual and behavioural reference: keep its look, layout, copy and calculation rules, and replace its localStorage with a server-side API and authentication. Everything needed to reproduce the design is also written out below, so the build does not depend on the prototype alone.

---

## 1. Goals

- Log a shift in under 15 seconds from a phone.
- Show the month's payout at a glance: worked, planned and total, plus bonuses.
- Keep the data on the user's own server, behind a login that is only needed once per device.
- Run with `docker compose up -d` and nothing else: no build step, no Node, no external services.

**Non-goals:** multi-user or team features, payroll tax or deduction calculation, overtime rules, invoicing, native mobile apps, offline editing. Design the data layer so a second user could be added later, but do not build it now.

---

## 2. Tech and deployment constraints

- **Language and server:** PHP 8.3 on the official `php:8.3-apache` image. No framework and no Composer dependencies unless clearly justified. Plain PHP files, a tiny router, PDO.
- **Storage:** SQLite through PDO (`pdo_sqlite` ships with the official image). The database file lives at `/data/shiftpay.sqlite` on a named volume. Enable WAL mode and foreign keys. Use a simple migration table, so schema changes can be applied on startup.
- **Front end:** server-rendered shell (`index.php`) plus vanilla JavaScript and one CSS file. No bundler. Self-host the two web fonts (Bricolage Grotesque, Figtree) as woff2 files under `public/assets/fonts` instead of using Google Fonts, so the app works on a private network.
- **Document root** is `/var/www/html/public`. Source code, migrations and `/data` must not be web-accessible.
- **Docker stack:** `Dockerfile` and `docker-compose.yml` in the repo root.
    - Service `shiftpay`, port `8080:80`, `restart: unless-stopped`, named volume `shiftpay-data` mounted at `/data`.
    - Environment variables: `TZ`, `SETUP_TOKEN` (optional, see section 4), `SESSION_DAYS` (default 365), `TRUST_PROXY` (default `false`; when `true`, honour `X-Forwarded-Proto` and `X-Forwarded-For` so cookies get the `Secure` flag behind a TLS reverse proxy).
    - Healthcheck hitting `/healthz` (returns 200 and `ok`, no auth).
    - Run as `www-data`. The `/data` directory is created and owned on first start.
- Provide a short `README.md` covering: start the stack, first-run setup, putting it behind a reverse proxy (Traefik or Caddy example), and backup and restore of the volume.

Suggested layout:

```
/
  Dockerfile
  docker-compose.yml
  README.md
  src/            # Db.php, Auth.php, Router.php, ShiftRepo.php, helpers
  migrations/     # 001_init.sql ...
  public/
    index.php     # app shell (requires auth, else redirects to /login)
    login.php, setup.php, healthz.php
    api/index.php # JSON API front controller
    assets/       # app.js, app.css, fonts/, icons/, manifest.webmanifest
```

---

## 3. Data model

All money is stored as **integer minor units** (cents) to avoid floating-point errors. The API sends and receives decimal numbers in major units (e.g. `12.50`); convert at the boundary.

**settings** (one row, `id = 1`)
| field | type | notes |
|---|---|---|
| wage_cents | integer | default hourly wage, used for new shifts |
| currency_symbol | text | default `$`, max 4 chars |
| updated_at | text | ISO 8601 |

**shifts**
| field | type | notes |
|---|---|---|
| id | integer PK | |
| date | text `YYYY-MM-DD` | local calendar date the shift starts on. No timezone conversion. |
| start_time | text `HH:MM` | 24 h |
| end_time | text `HH:MM` | if `end <= start` the shift runs past midnight |
| break_minutes | integer | unpaid, default 0 |
| rate_cents | integer | hourly rate frozen on the shift when saved |
| bonus_cents | integer | default 0 |
| note | text | optional, max 80 chars |
| status | text | `worked` or `planned` |
| created_at, updated_at | text | ISO 8601 |

Index on `date`.

**users**: `id`, `password_hash` (argon2id, fall back to bcrypt), `created_at`. Single row for now.

**device_sessions**: `id`, `user_id`, `token_hash` (SHA-256 of the cookie token), `label` (parsed from the user agent, e.g. "Chrome on Android"), `created_at`, `last_seen_at`, `expires_at`.

**login_attempts**: `ip`, `attempted_at`, used for throttling.

### Calculation rules (must match the prototype exactly)

- `minutes = (end - start)`, add 1440 if the result is `<= 0`, then subtract `break_minutes`. Clamp at 0.
- `hours = minutes / 60`.
- `base pay = hours × rate`. `shift total = base pay + bonus`. Round to whole cents once, per shift, after the multiplication.
- Month totals group shifts by the `YYYY-MM` prefix of `date`:
    - **Worked** = sum of totals where `status = worked`, with its hours.
    - **Planned** = sum of totals where `status = planned`, with its hours.
    - **Payout** = worked + planned.
    - **Bonuses included** = sum of `bonus_cents` across both statuses.
- A shift belongs to the month of its start date, even when it ends after midnight.
- Hours display as `7 h 30 min`, `45 min` or `8 h`.
- Each shift keeps the rate it was saved with. Changing the default wage affects only new shifts, unless the user ticks "Also update planned shifts to this wage", which rewrites `rate_cents` on `planned` shifts only.

---

## 4. Authentication: sign in once, stay signed in

One user, one password. The login is needed once per device. Every page and API endpoint except `/login`, `/setup` and `/healthz` requires a valid session.

**First run.** While no user exists, `/setup` lets the owner choose a password (minimum 10 characters, entered twice). If `SETUP_TOKEN` is set, `/setup` also asks for it, so a stranger who finds a freshly exposed instance cannot claim it. Once a user exists, `/setup` returns 404.

**Sign-in.** `/login` takes the password and shows a "Keep me signed in on this device" checkbox, checked by default.
- On success, generate a random 32-byte token, store only its SHA-256 hash in `device_sessions`, and set a cookie holding the raw token.
- Cookie: `HttpOnly`, `SameSite=Lax`, `Secure` when the request is HTTPS (or `TRUST_PROXY` says so), path `/`, lifetime `SESSION_DAYS` (default 365 days). If the box is unchecked, make it a browser-session cookie.
- **Rolling renewal:** when `last_seen_at` is more than 24 hours old, update it and extend `expires_at` and the cookie. A device in regular use therefore never has to sign in again.
- Regenerate the token on every sign-in. Never put the token in a URL.

**Protection.**
- Throttle failed logins per IP: after 5 failures in 15 minutes, respond with an increasing delay or a "Try again later" message. Use a constant-time comparison (`password_verify`).
- Mutating API requests (`POST`, `PUT`, `PATCH`, `DELETE`) must be `application/json` and carry a matching `Origin` or `Host`. Combined with `SameSite=Lax`, this is the CSRF defence.
- Security headers on every response: `Content-Security-Policy` (self only; inline styles allowed only if needed), `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: DENY`.
- Unauthenticated API calls return `401` JSON. The front end then redirects to `/login`.

**Account controls (in Settings).**
- Change password (asks for the current one; keeps this device signed in, signs out all others).
- **Signed-in devices:** list with label, last seen, "This device" marker, and a Sign out button per device.
- **Sign out** of this device.
- Password reset is done from the command line: document `docker compose exec shiftpay php bin/reset-password.php` in the README, and include that script.

---

## 5. Features and screens

### 5.1 Main screen (month view)
- **Header:** previous and next month buttons around the month and year title; a settings button on the right.
- **Hero panel** (solid accent colour, rounded 22 px): label "October payout", the big total, then a thin two-part bar showing the worked share (solid) and planned share (diagonal stripes). Below it a two-column split: **Worked** (amount, hours) and **Planned** (amount, hours). A divider and a line "Bonuses included" with the amount.
- **Shift list** for the month, sorted by date then start time. Each row is a card with:
    - a date block on the left (weekday abbreviation and day number): filled soft-accent for worked shifts, dashed blue outline for planned;
    - the note, or "Shift" or "Planned shift" if there is none, with a "Planned" pill on planned shifts; below it `9:00 AM – 5:00 PM · 7 h 30 min`;
    - the shift total on the right, with an amber "+$20.00 bonus" pill when there is a bonus;
    - for planned shifts whose date is today or earlier, a **Mark worked** button that flips the status in one tap.
    - Tapping or clicking the row opens the edit sheet.
- **Empty state:** "No shifts in October" and "Add a worked or planned shift to see your payout."
- **Add shift** floating button, bottom right (on desktop it moves into the page header, see 5.4).

### 5.2 Add and edit shift sheet
Bottom sheet on phones, centred modal on desktop. Fields in this order:
1. Status toggle: Worked / Planned. Defaults to Planned when the date is after today, otherwise Worked, and follows the date until the user taps the toggle by hand. When editing, it shows the saved status.
2. Date (defaults to today, or the first of the month being viewed when that is not the current month).
3. Start (09:00) and End (17:00).
4. Unpaid break in minutes (30) and Hourly rate (prefilled with the default wage).
5. Bonus for this shift (optional).
6. Note (optional, 80 chars).

A live preview line updates as the user types: `7 h 30 min × $15.00 = $112.50 + $20.00 bonus` and the total in large type. Validation messages are plain and specific ("Enter a start and end time.", "The break is longer than the shift."). Buttons: Cancel, Save shift, and when editing, Delete shift, which needs a second tap ("Tap again to delete"). After saving, jump to the month of the saved shift.

### 5.3 Settings sheet
- Default hourly wage and currency symbol. On first visit after setup, open this sheet automatically with the title "Set your hourly wage".
- Checkbox "Also update planned shifts to this wage".
- **Data:** Export all shifts as CSV; Export a full JSON backup; Import a JSON backup (confirm before replacing existing data, show how many shifts will be imported). These are real file downloads and uploads now.
- **Account:** change password, signed-in devices, sign out (section 4).

### 5.4 Desktop layout
The same screens, adapted. Do not ship a stretched phone layout.
- Below 900 px: the single column from the prototype (max width 560 px, centred).
- At 900 px and above: two columns inside a 1100 px container. The **left column** (about 360 px, sticky) holds the month navigation and the hero panel. The **right column** holds the shift list, with an **Add shift** button in its header instead of the floating button.
- Add/edit and Settings open as centred modals (max width 520 px) with a dimmed backdrop, not bottom sheets.
- Keyboard: `N` opens Add shift, `←` and `→` change month, `Esc` closes any open sheet, `Enter` saves in the shift form. Show these in a small hint at the foot of the page.
- Visible hover states on rows and buttons, and a focus ring on everything interactive.
- Tablet in landscape follows the desktop layout.

### 5.5 Installable web app
Provide `manifest.webmanifest` (name "Shift Pay", `display: standalone`, theme and background colours from the palette, 192 and 512 px icons including a maskable one) so it can be added to an Android or desktop home screen. A service worker is optional and not required; if added, cache only static assets, never API responses.

---

## 6. API

JSON over HTTPS, under `/api`. All require a session. Money fields are decimal numbers in major units. Errors use `{ "error": "message" }` with the right status code (400 validation, 401 unauthenticated, 404, 429 throttled).

| method and path | purpose |
|---|---|
| `GET /api/me` | `{ settings, deviceLabel }`, also the session check on load |
| `GET /api/shifts?month=YYYY-MM` | shifts for that month, sorted |
| `GET /api/summary?month=YYYY-MM` | `{ worked: {pay, hours}, planned: {pay, hours}, payout, bonuses }`, computed by the server with the rules in section 3. The client displays these numbers and does not recompute them. |
| `POST /api/shifts` | create |
| `PUT /api/shifts/{id}` | update |
| `DELETE /api/shifts/{id}` | delete |
| `POST /api/shifts/{id}/worked` | mark as worked |
| `PUT /api/settings` | wage, symbol, `applyToPlanned` boolean |
| `GET /api/export.csv`, `GET /api/export.json` | downloads |
| `POST /api/import` | replace all shifts from a JSON backup, in one transaction |
| `GET /api/devices`, `DELETE /api/devices/{id}` | signed-in devices |
| `POST /api/password` | change password |
| `POST /api/logout` | sign out this device |

Validate on the server even though the form validates too: date format, `HH:MM` times, non-negative amounts, `status` in the allowed set, note length. Use prepared statements everywhere. Escape all output in the HTML shell and render notes with `textContent` on the client.

---

## 7. Visual design (keep what the prototype has)

**Character:** calm, practical and money-aware. A deep green hero panel is the single memorable element; everything else stays quiet. Not cream and terracotta, not neon.

**Colour tokens**, defined as CSS variables on `:root`, redefined under `@media (prefers-color-scheme: dark)`. No manual theme toggle is needed.

| token | light | dark |
|---|---|---|
| `--bg` | `#EDF1F3` | `#0E181D` |
| `--surface` | `#FFFFFF` | `#17252C` |
| `--ink` | `#14232B` | `#E8F0F2` |
| `--muted` | `#5B6B73` | `#93A5AD` |
| `--line` | `#D6DEE2` | `#2A3B43` |
| `--accent` (hero, buttons) | `#0E6B5C` | `#3FB89E` |
| `--accent-ink` | `#FFFFFF` | `#04211B` |
| `--accent-soft` | `#CFE8E2` | `#1E4A41` |
| `--bonus` / `--bonus-bg` | `#A8480A` / `#FBE9D4` | `#F4B35E` / `#3A2A14` |
| `--planned` / `--planned-bg` | `#2F4CB3` / `#E3E9FB` | `#9DB1FF` / `#232C4D` |
| `--danger` | `#B3261E` | `#FF8A80` |

**Type:** Bricolage Grotesque (700 for the hero number, month title, amounts and sheet titles) and Figtree (400 to 600 for everything else). Use tabular figures for all money. Hero number is `clamp(44px, 14vw, 64px)` with tight letter spacing; at desktop widths it can go up to 72 px. Sentence case everywhere, no all-caps labels. Keep line lengths short.

**Shape and spacing:** hero radius 22 px, cards 16 px, inputs and buttons 12 to 14 px, pills fully rounded. 8 px base spacing. Inputs are at least 48 px tall with 16 px text (prevents zoom on iOS), and every tap target is at least 44 px. Respect `env(safe-area-inset-*)` on phones. Motion: only the sheet entrance (a short slide-up, under 200 ms), and none under `prefers-reduced-motion`.

**Copy:** plain, active, specific. Buttons say exactly what they do ("Save shift", "Mark worked", "Add shift"). Use typographic apostrophes and quotes (’ “ ”) in visible text. Errors say what to fix and do not apologise.

**Accessibility:** WCAG AA contrast in both themes, labels tied to inputs, dialogs with `role="dialog"`, `aria-modal`, focus moved into the dialog on open and returned on close, Esc to close, rows reachable and operable by keyboard, `aria-live="polite"` on the hero total.

---

## 8. Quality bar and acceptance criteria

The build is done when all of these pass:

1. `docker compose up -d` on a clean machine serves the app on port 8080; `/healthz` returns 200.
2. First visit shows setup; after the password is set, `/setup` is gone. With `SETUP_TOKEN` set, setup fails without it.
3. Sign in on a phone and a desktop browser. Close the browser, wait, reopen: still signed in. Restart the container: still signed in and all data present.
4. Signing out one device in the devices list makes that device's next request return 401 and land on `/login`, and leaves the others signed in.
5. Five wrong passwords in a row triggers throttling.
6. Calculation tests (unit-level, runnable with `php` alone) cover: normal shift, overnight shift (22:00 to 06:00), break longer than shift, rounding (e.g. 7 h 20 min at 13.37), bonus only, month boundary (a shift starting 31 Oct at 22:00 counts in October), and the worked/planned split. These match the prototype's results.
7. Adding, editing, deleting, and marking worked all update the hero and list without a full page reload.
8. Changing the default wage leaves existing shifts unchanged; with "Also update planned shifts" ticked, only planned shifts change.
9. JSON export followed by import into an empty database restores identical shifts and settings. CSV opens correctly in a spreadsheet (UTF-8 with BOM, ISO dates, decimal point).
10. Layout checks at 360 px, 768 px and 1280 px wide in light and dark mode; no horizontal scroll at any width; the two-column desktop layout appears at 900 px and above.
11. Lighthouse accessibility score of 95 or more on the main screen.
12. No secrets or password hashes in logs; the database file and source are not reachable by URL.

---

## 9. Build order for Claude Code

1. Dockerfile, compose file, `/healthz`, router skeleton, SQLite migrations.
2. Auth: setup, login, rolling device sessions, throttling, logout, devices, password change and the CLI reset script.
3. Shift and settings repositories, calculation helpers with unit tests, JSON API.
4. Front end: port the prototype's markup and CSS, swap localStorage for the API, add the desktop layout and keyboard shortcuts.
5. Export, import, manifest and icons.
6. README, then run through the acceptance criteria and fix what fails.