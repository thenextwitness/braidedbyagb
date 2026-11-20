# BraidedbyAGB: standing instructions

BraidedbyAGB is a hair-braiding business site (bookings, shop, academy, customer
and stylist portals, admin, plus the Android admin app in `mobile/`). Plain PHP
8.3 + MySQL/MariaDB, no framework. It is a business, separate from The Next
Witness ministry projects.

## NEW (20 Nov 2026): the site moved host. Read `docs/MIGRATION-HANDOVER-2026-11-20.md` first

It explains what was done while this session was idle, what changed for you,
and what is still open. Then use `docs/ORACLE-HOSTING.md` day to day.

## Hosting and deploy: read `docs/ORACLE-HOSTING.md`

- The live site runs on an **Oracle Cloud VM** (Caddy + PHP-FPM + MariaDB), not
  Namecheap. Namecheap still holds the domain's **email** only.
- **A push to `session-improvements` (the default branch; there is no `main`) deploys automatically** (`.github/workflows/deploy-oracle.yml`):
  PHP lint → rsync to the server → check `/version.txt` shows the new commit.
  A feature is not done until that workflow run is green.
- Never deploy via cPanel; never edit files directly on the server.
- `config/` and `uploads/` are server-only: never commit secrets, never expect a
  deploy to change them.
- Database changes: add idempotent SQL to `database/migrations.sql`, push, then
  run `database/migrate.php?key=...` once. Deploys do not touch the database.
- Routes: Caddy does not read `.htaccess`. Adding or changing a route in any
  `.htaccess` also needs the server's Caddy rules updated (see the hosting doc).
  Flag this to the owner rather than assuming the new URL works.

## Working rules

- Read a file fully before changing it, and check what calls it.
- Run `php -l` on every changed PHP file before committing.
- After deploying, check the changed page or endpoint on the live site.
- Money paths (Stripe checkout, deposits, refunds, payouts, accounting journal)
  need extra care: test with Stripe test mode or a low-value real case, and say
  plainly what was and was not verified.
