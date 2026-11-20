# BraidedbyAGB on Oracle Cloud: hosting and deploy handover

Written 20 Nov 2026 when the site was moved off Namecheap shared hosting.
Namecheap still hosts **email** (cPanel webmail, MX records) and the owner's
**static sites**. Only the braidedbyagb.co.uk website and its database moved.

## How a change goes live

1. Commit and `git push` to `session-improvements` (the default branch; there is no `main`).
2. GitHub Actions (`.github/workflows/deploy-oracle.yml`) runs automatically:
   - checks every PHP file for syntax errors (any error stops the deploy);
   - writes the commit hash to `version.txt`;
   - rsyncs the repo to the server (`/srv/braided`), skipping `.deployignore`;
   - checks that https://braidedbyagb.co.uk/version.txt shows the new commit.
3. Check the run in GitHub → Actions. Green means it is live.

Never deploy through cPanel any more: cPanel's copy is no longer the live site.

**Database changes are automatic too.** Add a guarded `step()` to the bottom of
`database/migrate.php` (each step checks whether it is already applied) and push.
After the files land, the server notices the deploy (systemd `braided-migrate.path`
watching `version.txt`) and, **only if `migrate.php` changed**, backs the database
up to `/var/backups/braided/pre-migrate-*.sql.gz` (last 20 kept) and runs it.
The result goes to `https://braidedbyagb.co.uk/migrate-status.txt`
(`<commit> unchanged` / `<commit> ok N applied` / `<commit> failed: <step names>`),
and the workflow's last step waits for it: a red run means a step reported ERROR.
Full output is in `/var/log/braided-migrate.log` on the server (owner only).
No key or server login is involved, so the Braided session can do all of this.

- A run re-checks every step, so data-fixing steps (such as "backfill past
  bookings to completed") also run whenever `migrate.php` changes. Keep every
  step's "already applied" check exact, and never write a step that deletes or
  rewrites customer data without the owner agreeing first.
- `database/migrations.sql` is an old stub that is never executed: SQL added there
  would silently never apply, so the deploy fails if any appears in it.
- Manual run, if ever needed (owner): `https://braidedbyagb.co.uk/database/migrate.php?key=<MIGRATE_KEY>`
  (the key is in the server's `config/database.php`).

## The server

| Thing | Value |
|---|---|
| Host | Oracle `mainie` VM, 141.147.86.105, Ubuntu 24.04, 1 GB RAM + 6 GB swap |
| Shared with | Mainie Care (app.mainiecare.co.uk), so keep memory use modest |
| Web server | Caddy (auto HTTPS): `/etc/caddy/Caddyfile` + `/etc/caddy/sites/braided-snippets.caddy` |
| PHP | 8.3 FPM; limits in `/etc/php/8.3/fpm/conf.d/90-braided.ini` (16 MB uploads, Europe/London) |
| Database | MariaDB 10.11 on localhost, database `braidedbyagb`, user `braided` |
| Site files | `/srv/braided` |
| Secrets | `/srv/braided/config/database.php` (server only, never in git, never deployed) |
| Customer files | `/srv/braided/uploads/` (server only, never deployed or deleted by deploys) |
| Scheduler | `/etc/cron.d/braided`, every 30 min, runs `cron/scheduler.php`, logs to `/var/log/braided-cron.log` |
| Backups | nightly 03:15 database dump to `/var/backups/braided/`, kept 14 days |
| Deploy login | user `deploy`, restricted to rsync into `/srv/braided` only |

### URL routing (replaces .htaccess)

Caddy does not read `.htaccess`. The routes in `.htaccess`, `admin/.htaccess` and
`api/.htaccess` were translated into Caddy rules in `braided-snippets.caddy`.
**If you add or change a route in any `.htaccess`, the Caddy rules must be updated
too**, or the new URL returns 404. That file lives on the server, not in this repo;
ask the owner or the Oracle infrastructure session to apply it.

Apache used only the deepest `.htaccess` for a request: `/admin/...` uses
`admin/.htaccess`, `/api/...` uses `api/.htaccess`, everything else the root one.
The Caddy rules follow the same split.

Blocked from the web: `config/`, `vendor/`, `composer.*`, any `.sql/.bak/.log/
.zip/.env/.json/.lock` file, dotfiles, and any script inside `uploads/`.

## Differences from the old host (checked during the move)

- **PHP 8.3 here, PHP 8.0.30 on Namecheap.** All 44 public pages were compared
  against the live site and run with no PHP warnings. One real break was fixed
  in code: `public/service-detail.php` and `public/product.php` built their
  JSON-LD with `addslashes()`, which on PHP 8.1+ produces invalid JSON for any
  apostrophe (`htmlspecialchars_decode` now decodes `'` by default). They now use
  `json_encode`. Keep using `json_encode` for anything placed inside JSON or JS.
- **Database values come back as strings**, as they did on cPanel:
  `getDB()` in `config/database.php` sets `PDO::ATTR_STRINGIFY_FETCHES => true`.
  Without it the server's mysqlnd driver returns real ints, and existing `===`
  comparisons between DB values and request values would silently fail.
  If `config/database.php` is ever replaced, keep that line.
- PHP errors are logged to `/var/log/php/braided-error.log` (never shown to visitors).

## Cutover: DONE 20 Nov 2026 (~01:15 UK)

- Data: live export of 19 Nov 19:43 (55 tables, 147 bookings, last booking change
  19 Nov 14:10; the owner confirmed no activity after it) + 39 upload files.
- Server config built from the live cPanel `config/database.php`. Only DB_*, the
  pinned AUTH_CODE_PEPPER and STRINGIFY_FETCHES differ. Backups of earlier
  versions sit beside it in `config/` (server only).
- cPanel cron jobs deleted by the owner; the Oracle scheduler is on. Checked
  before enabling: no review requests due and no pending bookings, so nothing
  the cPanel cron had already sent could go out twice.
- DNS: the `braidedbyagb.co.uk` A record is now 141.147.86.105 (`www` is a CNAME to
  it). MX, SPF and mail records are untouched, so email stays on Namecheap.
  Let's Encrypt certificates for both names were issued by Caddy.
- First GitHub Actions deploy green: the live site reported commit 8931e97.
- The old cPanel copy still exists. Visitors whose DNS was cached could reach it
  for up to ~4 hours after the switch (record TTL 14400). Check its database
  (cPanel phpMyAdmin) once for bookings, orders or customers created after
  19 Nov 14:10, and copy any across.

## Known gaps carried over from Namecheap

- Stripe webhook: on cPanel only `/api/webhook.php` worked; the short
  `/api/webhook` returned 404 because `api/.htaccess` overrode the root rule.
  The Oracle Caddy rules now route both to `api/webhook.php`. Check Stripe →
  Developers → Webhooks: if the endpoint was `/api/webhook`, deliveries were
  failing on cPanel and will start succeeding after cutover.
- Service slugs lose the first letter of each capitalised word ("Takedown
  Service" became `akedown-ervice`), so the slug generator drops capitals before
  lower-casing. Existing URLs are live and indexed: fix the generator for new
  services, and only change old slugs with redirects.
- Admin delete confirmations (`admin/addons.php`, `admin/products.php`,
  `admin/includes/service-row.php`) put `addslashes()` text inside an HTML
  attribute without HTML-escaping, so a name containing `"` breaks the button.
- The live Stripe secret key and Resend API key should be rolled (they were
  exposed in a working log during the move); update `config/database.php` after.
