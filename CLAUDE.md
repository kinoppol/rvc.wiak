# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this repository is

A PHP 8 / MariaDB 10 web app implementing **ระบบมอบหมายและติดตามงาน (EduTask Tracking)** — a task assignment and tracking system for a Thai vocational college. It is a hand-rolled MVC app with **no framework, no Composer dependencies, and no JS build step** — deploy by dropping the files on Apache/PHP and running the installer.

The UI/domain design originated as a Claude Design prototype; see "Design reference" below.

## Running it locally

There is no build step. To develop:

1. Point Apache (or any PHP web server) at the repo root as document root.
2. Visit `/install.php` and complete the wizard (checks PHP/MariaDB requirements, creates the database from `database/schema.sql`, writes `config/config.php`, creates the first admin user).
3. To reset a local install: delete `config/config.php` and `config/install.lock`, drop the MariaDB database, and re-run `install.php`.

**Lint check** (no other automated checks exist): `find . -name "*.php" -not -path "./project/*" -exec php -l {} \;`

There are no PHPUnit/other automated tests in this repo. Verify behavior by running the app in a browser against a real MariaDB instance.

## Architecture

**No Composer, no PSR-4 via vendor/** — `app/bootstrap.php` registers a manual `spl_autoload_register` mapping `App\*` to `app/*.php`. Every request goes through `index.php`, which requires `app/bootstrap.php` (loads `config/config.php`, configures `Database`/`Upload`, starts the session) and then dispatches via `App\Core\Router` — a small regex-based router matching `{param}` segments, registered as a flat list in `index.php`.

**Request flow**: `index.php` → `Router::dispatch` → a `Controller` (`app/Controllers/`) → `Model` static methods (`app/Models/`, thin PDO wrappers, no ORM) → a `View` rendered from `app/Views/*.php` (plain PHP templates, no template engine).

**Two response shapes from controllers**:
- Full pages: `Controller::page()` → `View::layout()` wraps a view in `app/Views/layout.php` (sidebar/header/theme chrome).
- AJAX fragments: `Controller::html()` returns a partial's raw HTML (`app/Views/partials/*.php`) with no layout, for injection into the client-side overlay/board containers. `Controller::json()` returns `{ok, ...}` for action endpoints.

**Client-side is vanilla JS, no framework**: `public/assets/js/app.js` uses one set of `document`-level delegated listeners for everything —
- `[data-open-ticket]` / `[data-open-new-ticket]` → fetch a partial and inject into `#overlay-root`.
- `[data-post="/path"]` → POST with CSRF, then re-fetch whatever's open (`data-refresh-ticket="<id>"` or the board), or `location.reload()` if `data-reload-on-success` is set.
- `[data-ajax-form]` on a `<form>` → same pattern via the `submit` event, `data-refresh-ticket` / `data-reload-on-success` control what happens after.
- `[data-close-overlay]` closes `#overlay-root`, **and only an explicit element carrying that attribute can close it** — by product decision, modals/drawers must close only via a visible close button, never by clicking the backdrop or pressing Escape (there is deliberately no backdrop-click or Escape-key handler). When adding a new overlay partial, put `data-close-overlay` **only on the actual close button(s)** (an X icon and/or a "ยกเลิก" button), never on the outer `.detail-overlay`/`.modal-overlay` wrapper — see `ticket_detail.php` / `ticket_new.php` for the pattern. Also do **not** add `onclick="event.stopPropagation()"` on modal/drawer panels; that was tried earlier and silently broke every delegated click handler inside the panel (a real bug fixed during initial build).
- `App\Core\Url::asset()` resolves to `/public/assets/...` — CSS/JS must live under `public/assets/{css,js}/`, not `public/{css,js}/`.
- `App\Core\Url::to()`/`Url::asset()` already prepend the app's base path (`dirname(SCRIPT_NAME)`, e.g. `/rvc.wiak`). `app.js`'s `resolveUrl()` is base-path-aware (won't double-prefix a PHP-rendered `<form action>` that's already base-prefixed) — when adding new `data-post`/`fetchHtml` call sites, pass **app-relative** paths (`/tickets/5/approve`), not `Url::to()`-prefixed ones, unless going through a `<form action>`.

**Auth/session** (`App\Core\Auth`): `$_SESSION['real_user_id']` is the actual logged-in account; `$_SESSION['user_id']` is the *effective* identity (differs while an admin is impersonating via `$_SESSION['impersonating']`). `Auth::user()` returns the effective identity — use this everywhere except when specifically checking "who is really logged in" (e.g. re-authorizing an admin-only action) which should use `Auth::realUser()`. `$_SESSION['active_role']` holds which of the user's multiple roles is currently selected (role-switcher in the sidebar); most permission checks key off `Auth::activeRole()`, not the full role list.

**Permission model**: roles have a `hierarchy_level` (`director`=1 … `staff`=5, `admin`=NULL/outside the chain) and an `is_assigner` flag, seeded in `database/schema.sql` and read via `App\Models\Role`. `Role::isAssigner($code)` decides which action set a ticket detail view shows. `TicketController` additionally restricts assigner-only actions (approve/force-close/reassign/answer) to the ticket's actual `from_user_id` or an admin (`assertAssignerOwner`) — this is intentionally stricter than the original prototype, which let any user with an assigner-role code act on *any* ticket.

**Cross-level detection** (`Role::isCrossLevel`): a ticket is flagged `is_cross` automatically at creation/reassignment time when the assignment skips a level in the hierarchy (e.g. director straight to staff, skipping deputy/dept → supervisor). This is a designed interpretation, not given explicitly by the original mockup — reconsider before changing without checking existing `is_cross` data.

**Duration analytics are computed, not stored as text**: `tickets` has individual timestamp columns (`opened_at`, `ack_at`, `doing_at`, `submitted_at`, `closed_at`) and `App\Models\Ticket::durations()` / `elapsedLabel()` compute human-readable Thai duration strings from them on read. Every status transition and the "first open" event also appends a row to `ticket_timeline` (`TicketTimeline::add`) — this is what drives the "Timeline การดำเนินงาน" panel; don't bypass `Ticket::setStatus()` with raw UPDATEs or the timeline/duration data goes stale.

**File uploads** (`App\Core\Upload`): stored under `storage/uploads/{ticket_id}/{random}.{ext}`, original filename kept only in the DB (`ticket_files.name`), never used as the on-disk filename. Max size enforced both against the configured `upload.max_bytes` (set during install, capped to the server's actual `upload_max_filesize`/`post_max_size` ini ceiling) and PHP's own limits.

**External people sync** (`App\Services\PeopleSync`, `App\Models\Setting`): imports user accounts from the school's RMS personnel system. The `settings` table is a generic key/value store; only the base URL (`external_api_base_url`, protocol+host, editable by admins at `/admin/settings`) lives there — the endpoint path (`/api_connection.php?app_name=nutty&data=people`) is a constant in `PeopleSync`, not configurable, per the original request. Matching/upsert is by `username` (mapped from the source's `people_id`, e.g. a Thai national ID number); only records with `people_exit == 0` are imported, records with an empty `ath_pass` are skipped (never create an account with a blank password). Re-running the sync updates `full_name`/`email`/`password_hash` for existing accounts but **must never touch `created_at`** — don't "simplify" this into a blind `INSERT ... ON DUPLICATE KEY UPDATE` covering all columns. New accounts get the `staff` role only at creation time; re-syncing an existing account does not touch its role assignments (an admin may have since changed them). The source system returns real personal data (national IDs, names, password hashes) — be careful with scratch files/logs when working on this code locally.

**Admin user management** (`/admin/users`, `AdminController::manageUsers`, `App\Models\User::searchPaged`): a plain server-rendered, GET-form-driven search/role-filter/pagination page (no AJAX) — appropriate given the real user count is 100+ after a people-sync. Impersonation ("สวมสิทธิ์") lives here as a per-row button (`[data-impersonate-user]`, handled in `app.js`), not as a separate picker modal — that modal was removed by design; don't re-add a standalone impersonate picker.

**Security boundaries**: `app/`, `config/`, `database/`, `storage/`, `install/` each have an `.htaccess` with `Require all denied` since the whole repo (not just a `public/` doc-root) is expected to sit under the web root — don't remove these or add PHP files there without confirming they can't be requested directly. CSRF tokens (`App\Core\Csrf`) are required on every POST (form field or `X-CSRF-Token` header); AJAX POSTs from `app.js` append `csrf` to the `FormData` automatically.

## Design reference

[project/ระบบมอบหมายและติดตามงาน.dc.html](project/ระบบมอบหมายและติดตามงาน.dc.html) is the **original Claude Design prototype** this app was built from — a proprietary templating DSL (`dc-runtime`, see `project/support.js`, generated/do-not-edit) rendered to React for mockup purposes only. It is not part of the running app and is kept for reference. If UI behavior is ambiguous, this file (plus its embedded `class Component extends DCLogic` logic and mock `TICKETS`/`ROLES`/`PEOPLE` data) is the source of design intent — the real data model in `database/schema.sql` is a superset/refinement of those mock shapes (e.g. real timestamps instead of hard-coded duration strings).

Known deliberate deviations from the prototype (don't "fix" these back without checking with the user):
- Sidebar menu is collapsed to what's actually implemented (Dashboard, plus User Management/Audit Log/Settings for admins) rather than the prototype's per-role page list, since only one board+detail view exists.
- Force-close and approve are restricted server-side to the ticket's assigner/admin (prototype's JS let anyone call `setStatus`).
