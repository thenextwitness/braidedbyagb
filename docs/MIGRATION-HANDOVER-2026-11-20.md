# START HERE: the hosting move of 19 to 20 Nov 2026 (handover to the Braided session)

This note is for the Claude session that works on BraidedbyAGB. While this
session was idle (last used 9 Nov), the owner had the site moved to a new host
by the owner's infrastructure session. Everything below happened. Read it once,
then use `docs/ORACLE-HOSTING.md` as the day-to-day reference.

## In one paragraph

braidedbyagb.co.uk no longer runs on Namecheap cPanel. Since 20 Nov 2026
(about 01:15 UK) it runs on an Oracle Cloud server (VM `mainie`, 141.147.86.105)
with Caddy, PHP 8.3 and MariaDB. **A `git push` to `session-improvements`
deploys the site automatically** through GitHub Actions. Email stays on
Namecheap (cPanel webmail, MX records unchanged). The project folder also
moved: it was `D:\BraidedbyAGB`, it is now `D:\Workspaces\Glory\BraidedbyAGB`,
so notes or memory that mention the old path are out of date.

## What changes for you

| Before (until 19 Nov) | Now (from 20 Nov) |
|---|---|
| Deploy by cPanel / `.cpanel.yml` / file upload | `git push` to `session-improvements`; the Actions run must be green |
| PHP 8.0.30 on LiteSpeed | PHP 8.3 FPM behind Caddy |
| `.htaccess` controls URLs | Caddy rules on the server control URLs; `.htaccess` is no longer read |
| MySQL on Namecheap (`jussxvwc_...`) | MariaDB 10.11 on the server, database `braidedbyagb` |
| cPanel cron jobs | Server cron, every 30 min, runs `cron/scheduler.php` (cPanel crons deleted) |
| Errors in `error_log` files | `/var/log/php/braided-error.log` on the server |
| Local `config/database.php` | Deleted locally (it was stale). The real one lives only on the server |

Things you **cannot** do from this session: log in to the server, change the
Caddy rules, read server logs, or change server config. Ask the owner; they
pass it to their infrastructure session. Things you **can** do: change code,
push (which deploys), and add database migrations (the server applies them
automatically after the deploy; see the rules at the bottom).

## The timeline of what was done

1. **Server prepared** (`mainie`, shared with the Mainie Care app, 1 GB RAM +
   6 GB swap): PHP 8.3 FPM (on-demand, max 6 workers, 16 MB uploads,
   Europe/London), MariaDB tuned for low memory, Caddy site for both
   `braidedbyagb.co.uk` and `www`.
2. **URL routing translated.** All 66 rewrite rules in `.htaccess`,
   `admin/.htaccess` and `api/.htaccess` were converted into Caddy rules
   (`/etc/caddy/sites/braided-snippets.caddy`), keeping Apache's behaviour that
   only the deepest `.htaccess` applies. Blocked from the web: `config/`,
   `vendor/`, `composer.*`, `.sql/.bak/.log/.zip/.env/.json/.lock` files,
   dotfiles, and scripts inside `uploads/`.
3. **Data copied from live.** The owner exported the live database on 19 Nov
   at 19:43 (55 tables, 147 bookings, last booking change 19 Nov 14:10) and the
   `uploads/` folder (39 files). Both were loaded on the server.
4. **Server config built from the live cPanel `config/database.php`.** Only
   these differ from live: the `DB_*` values (new local database and user), a
   pinned `AUTH_CODE_PEPPER` (so portal login codes issued before the move stay
   valid), and `PDO::ATTR_STRINGIFY_FETCHES => true` (see below). Stripe,
   Resend, reCAPTCHA, `MIGRATE_KEY` and the rest are the live values.
5. **Checked against the live site before switching.** All 44 public pages were
   compared with live and ran with no PHP warnings; admin, the portals and the
   app API were exercised. Two real differences were found and fixed:
   - PHP 8.3 returned database numbers as ints where cPanel returned strings,
     which would silently break `===` comparisons. Fixed in the server config
     with `STRINGIFY_FETCHES` (keep that line if the config is ever replaced).
   - JSON-LD in `public/service-detail.php` and `public/product.php` used
     `addslashes()`, which on PHP 8.1+ makes invalid JSON whenever a name or
     description has an apostrophe. Both now use `json_encode` (commit 8931e97).
     All 29 JSON-LD blocks were validated.
6. **Auto-deploy set up** (commit 8931e97): `.github/workflows/deploy-oracle.yml`
   lints every PHP file, writes the commit hash to `version.txt`, rsyncs to the
   server skipping `.deployignore`, then checks the live `/version.txt` shows
   the new commit. The server login it uses (`deploy`) can only rsync into the
   site folder: it cannot run commands, and deploys never touch `config/`,
   `uploads/` or the database. The key is the GitHub secret `ORACLE_DEPLOY_KEY`.
   The workflow can also be run by hand (Actions, "Run workflow").
7. **Cutover, 20 Nov ~01:15 UK.** The owner deleted the cPanel cron jobs and
   pointed the `braidedbyagb.co.uk` A record at 141.147.86.105 (`www` is a CNAME
   to it; MX/SPF untouched). Before turning on the new scheduler it was checked
   that nothing was due (no review requests, no pending bookings), so no email
   could go out twice. HTTPS certificates were issued automatically. The first
   deploy went green and the first scheduler run (01:30) was clean.
8. **Server updated, 20 Nov.** All pending Ubuntu updates installed (new kernel,
   Caddy 2.11.9) and the server rebooted. Afterwards the pages, admin, app API,
   Stripe webhook, database (147 bookings) and scheduler were all re-checked.
   Security updates now install automatically.

Commits on `session-improvements` from the move: 8931e97 (workflow, docs,
JSON-LD fix), 12645f3 (cutover record), and this note.

## Still open

Waiting on the owner (they have been told):
- Check the old cPanel database once (phpMyAdmin) for bookings, orders or
  customers created after 19 Nov 14:10, since cached DNS could have sent a few
  visitors to the old copy for up to ~4 hours. Anything found must be copied
  across by hand.
- Check Stripe → Developers → Webhooks: which endpoint URL is set, and whether
  deliveries succeed now (see the first known gap below).
- Roll (replace) the live Stripe secret key and the Resend API key, which were
  exposed in a working log during the move, then have the server config updated.

Code issues you can pick up (carried over from Namecheap, not caused by the move;
details in `docs/ORACLE-HOSTING.md` → "Known gaps"):
- Stripe webhook: on cPanel only `/api/webhook.php` worked; `/api/webhook`
  was a 404. Both now work on the new server.
- The service slug generator drops capital letters ("Takedown Service" became
  `akedown-ervice`). Fix it for new services; change old slugs only with
  redirects, since they are live and indexed.
- Admin delete confirmations (`admin/addons.php`, `admin/products.php`,
  `admin/includes/service-row.php`) put `addslashes()` text in an HTML
  attribute without HTML-escaping, so a name containing `"` breaks the button.

## Rules that came out of the move

- A push to `session-improvements` is a live deploy. Run `php -l` first and
  watch the Actions run go green, then check the changed page on the live site.
- Anything placed inside JSON or JavaScript: use `json_encode`, never
  `addslashes`.
- A new or changed route in any `.htaccess` will 404 until the server's Caddy
  rules are updated: tell the owner it is needed.
- Never ask for, print or commit the contents of `config/database.php`.
- Database changes go in `database/migrate.php` as a guarded `step()`, never in
  `database/migrations.sql` (a stub that is never run; the deploy now fails if
  SQL appears in it). Corrected 20 Nov after the Braided session spotted that
  the first version of these docs said the opposite.
- **Database changes now apply automatically** (added 20 Nov): push a new
  step in `migrate.php` and the server runs it after the deploy, with a backup
  first. You never need the migrate key or a server login. Details:
  `docs/ORACLE-HOSTING.md` → "Database changes are automatic too".
- The Android admin app (`mobile/`) talks to the same domain and API, so it
  needed no change for the move.
