# BraidedbyAGB Admin for Windows — Plan

A native Windows app (C++20, Qt 6 Widgets) that runs the business: bookings, clients,
services, money, shop and academy. It is built the way Purpose Intelligence (PI) is
built, and replaces the Electron wrapper in `desktop/` once it ships.

**Status:** awaiting the owner's approval of this plan and the mockup
(`mockups/agb-admin.png`). Nothing is built until then.

---

## 1. Owner decisions (30 Oct 2026)

| Decision | Choice | Why it matters |
|---|---|---|
| Where data lives | **Live from the website** (the admin API the phone app uses) | Bookings and Stripe payments land on the website. One source of truth: no second copy to disagree. |
| Offline | Recent data cached for **viewing** offline ("as last seen …"). **Changes need internet** (never queued). | A queued booking change could clash with a booking made online meanwhile. |
| Look | **Dark, like PI**, in BraidedbyAGB colours | Same frame as PI: side menu, top line, search, outlined cards. |
| First phase | **Daily running**: Today, Bookings, Calendar, Clients | What is used every day comes first. |
| Code home | `windows/` in this repo | The app and the website API it relies on change together. Not deployed to the web server (`.cpanel.yml` copies web folders only). |

## 2. Architecture (mirrors PI)

| Piece | What | PI equivalent |
|---|---|---|
| `windows/CMakeLists.txt` + `CMakePresets.json` | C++20, MSVC 2022 x64, Ninja, debug/release presets | same |
| Qt 6.10.3 `msvc2022_64` | installed by aqtinstall into `windows/vendor/qt` (git-ignored) | same |
| `agb_core` (static lib, **Qt-free**) | WinHTTP `HttpClient`, `AdminApi` (sign-in, every endpoint, typed results), `Cache` (last-seen JSON), `Config`, token store (DPAPI) | `pi_core`, `HttpClient`, `EcosystemConnector` |
| `BraidedbyAGBAdmin.exe` (Qt Widgets) | presentation only: shell, screens, `Theme` (one QSS from brand tokens), `ui::` kit | `PurposeIntelligence.exe` |
| JSON | `nlohmann::json`, non-throwing parse | same |
| Gates | build runs the exe with `--lint-tokens`, `--lint-words`, `--verify-shell`; failing gate fails the build | same |
| Self-checks | `--verify-api` (live, read-only, with a control that must fail), `--capture-<screen>` (PNG at 1536×832) | same |
| Deploy | `windeployqt` POST_BUILD; brand fonts/icons compiled in; **installer + single instance + update check** (PI has none yet — we add them) | partly |

`agb_core` must never link Qt (configure-time check, like PI's `pi_forbid_qt`).
No background service is needed: the website already runs the reminders and jobs.

## 3. Screens — every sub-feature

Side menu sections (Ctrl+number), plus **System** (Ctrl+0) and search (Ctrl+K).

**Today (Ctrl+1)**
- Today's appointments (time, client, service, option, status, deposit/balance), tap to open
- *Waiting on you*: bookings to confirm · bank transfers to check · new custom requests · reviews to approve · low stock
- Takings: today, this week, this month

**Bookings (Ctrl+2)**
- List: status tabs (pending, confirmed, completed, incomplete, cancelled), search by name/email/ref
- Booking record: details, add-ons, payment, payment log, notes, client contact (WhatsApp/email open outside)
- Actions with What/Who/When confirmations: confirm · complete · mark incomplete · cancel · reschedule (clash-checked) · set duration · mark deposit received · payment link (copy / regenerate / revoke) · delete (cancelled only)
- New booking (existing or new client, service/option/add-ons, date/time from free slots, deposit)
- Calendar: month view, blocked dates and times (block/unblock)

**Clients (Ctrl+3)**
- Client list + search; client record: bookings, notes, tags, loyalty points (adjust), block/unblock
- Reviews: approve/reject · Custom requests: reply + status · Discount codes: create/toggle

**Catalogue (Ctrl+4)**
- Services: edit price/duration/description/prep notes/category/rebook weeks/active/new badge; options (variants); add-ons; image
- Categories (order) · Gallery (upload, caption, service tag, hide, delete)

**Shop (Ctrl+5)** — Orders (status, dispatch) · Products & stock · Pipeline (products offered with services)

**Academy (Ctrl+6)** — Courses, cohorts, curriculum · Enrolments (manual/sponsored, payments, certificates) · Theory tests

**Money (Ctrl+7)** — Takings, expenses, pay yourself, profit · Payouts (only when multi-stylist mode is on)

**System (Ctrl+0)** — Settings (all website settings incl. WhatsApp number, deposit %, rebook reminders, multi-stylist switch) · Connection & health · Sign out · About/version · Logs

## 4. Website API work needed

The app only talks to `api/admin.php`. Already there: auth, dashboard, bookings, calendar,
customers, orders, settings, accounting, services, gallery, reviews, custom requests,
discounts, stylists, assignments, payouts. **To add** (each with the same checks as the web page):
products & stock, pipeline links, academy (courses/cohorts/modules/lessons), enrolments, quizzes,
and a `today` summary for the *Waiting on you* list.

## 5. Phases and exit criteria

| Phase | Builds | Done when |
|---|---|---|
| **W0 Foundations** | CMake project, Qt via aqtinstall, `agb_core` (HttpClient, AdminApi auth, Cache, token store), dark Theme + `ui::` kit, shell (side menu, top line, search, breadcrumbs), sign-in, splash, gates | the app signs in to the live site, shows the empty shell, and `--verify-api` passes with its control failing |
| **W1 Daily running** | Today, Bookings (list, record, all actions, new booking), Calendar, Clients | every Bookings action works end to end against the live site; offline shows "as last seen" |
| **W2 Catalogue** | Services (+options, add-ons, categories, rebook weeks), Gallery | editing a service round-trips with nothing lost (the bug that deleted services cannot recur) |
| **W3 Money** | Accounting, payouts (switch-aware) | numbers match the web Accounting page |
| **W4 Shop** | API + Orders, Products, Pipeline | |
| **W5 Academy** | API + Courses, Enrolments, Theory tests | |
| **W6 Ship** | installer, single instance, update check; remove `desktop/` | a fresh Windows PC installs it, signs in and works |

Each phase: build → post-fix audit → `--verify-*` passes → owner tries it → next phase.

## 6. Definition of done (per phase)
Works **end to end** against the live website, not "screen exists". Every action shows
"Saved." or "Not saved: reason" — nothing fails silently. No code words on screen. Main
action visible at 1440×900. Gates pass in the build. Honest status in each report.

## 7. Not included
Tap to Pay (needs a phone's NFC — stays in the Android app). Live chat (removed).
