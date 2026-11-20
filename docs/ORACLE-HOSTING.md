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

**Database changes are not automatic.** Add the SQL to `database/migrations.sql`
(idempotent, safe to re-run), push, then run the migration runner once:
`https://braidedbyagb.co.uk/database/migrate.php?key=<MIGRATE_KEY>`
(the key is in the server's `config/database.php`; ask the owner).

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

## Cutover status

- [x] Server built, code copied, routing translated and checked against the live site
- [x] Sensitive-file blocks, nightly backups, scheduler prepared (off)
- [x] Deploy user on the server (rsync-only, verified)
- [x] `ORACLE_DEPLOY_KEY` repo secret
- [x] Live data imported (export of 19 Nov 19:43: 55 tables, 147 bookings) + uploads
- [x] Server config built from the LIVE cPanel `config/database.php` (the copy in
      D:\BraidedbyAGB\config is older and lacks the reCAPTCHA + webhook keys; do
      not upload it). Only DB_*, the pinned AUTH_CODE_PEPPER and STRINGIFY_FETCHES differ.
- [ ] Final fresh export just before the DNS switch (bookings made after the
      first export would otherwise be lost); re-import
- [ ] Switch on the scheduler; add the domain to Caddy
- [ ] Owner: turn off the cPanel cron job (or reminder emails go out twice)
- [ ] Owner: cPanel Zone Editor, set `@` and `www` A records to 141.147.86.105
      (leave MX, SPF and mail records alone; email stays on Namecheap)
- [ ] Watch the first scheduler run and a test booking end to end

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
