# Moodle tester-env deployment notes (accepted baseline)

## Tested ref

- Fork `https://github.com/sma-rl-dev/moodle`, branch `tester-env-baseline`.
- Baseline: tag `v5.2.3` (latest stable; `v5.3.0-beta` prerelease skipped,
  `main` is dev) = commit `344232c15336c71b80f9aca8359ce0e0a9f3d116`.
- Tester-env tooling commit sits on top of the baseline commit on
  `tester-env-baseline`; upstream source files are untouched.

## Quick Start

```bash
./tester-env deploy    # Build image, start containers, CLI-install Moodle
./tester-env seed      # Populate deterministic Science Department dataset
./tester-env verify    # Check seeded state (login 200 + profile counts)
./tester-env reset     # compose down -v + rm config.php (image kept)
```

## Strategy

- `Dockerfile.web` FROM `moodlehq/moodle-php-apache:8.3` (PHP **runtime**
  image, no Moodle code) + `max_input_vars=5000`. App source is
  **bind-mounted** (repo root `.:/var/www/html`), so PHP mutations are live
  with **zero rebuild**.
- `docker-compose.tester-env.yml`: webserver (build + `image:
  ${IMAGE_TAG:-tester-env-moodle:dev}`, port `0.0.0.0:${PORT:-8000}:80`) +
  `postgres:17` (healthy `pg_isready` gate). Named volumes
  `moodle-pgdata` / `moodle-moodledata` (+`${RUN_ID_SUFFIX}` isolation).
  `IMAGE_TAG` is honoured from `scripts/rl-env` (content-addressed);
  `RUN_ID` only scopes containers/volumes/network, never the image name.
- Daemon default pools exhausted on first `up`, so the compose pins
  `10.202.0.0/24` (verified free; never touch `192.168.0.0/16` host LAN or
  `10.8.0.0/24` LAN-routed).
- `config.tester-env.php` (trimmed `config.docker-template.php` adaptation)
  is copied to `config.php` at deploy; `wwwroot` from `WEB_HOST`/`PORT`.
- Install via CLI only:
  `php admin/cli/install_database.php --agree-license --fullname="Docker moodle"
  --shortname="docker_moodle" --adminuser=admin --adminpass=<ADMIN_PASS>
  --adminemail=admin@example.com`. Credentials: **admin / test**
  (`ADMIN_PASS` env override; `$CFG->passwordpolicy=0`).

## Canonical URL

- `WEB_HOST` defaults to the default-bridge gateway `172.17.0.1`; canonical
  base is `http://172.17.0.1:8000` (host shell and bridge-network browser
  containers both HTTP 200 on `/login/index.php`). `http://localhost:8000`
  303-redirects to the canonical URL (Moodle redirects Host mismatches to
  `wwwroot`, so wwwroot MUST equal the browser URL).
- `config.php` reads `MOODLE_DOCKER_WEB_HOST` at request time, so switching
  `WEB_HOST` only needs a webserver recreate (`compose up -d`), never a
  reinstall.

## Rebuild cost

- Cold: pull runtime base + `postgres:17`, image build ~5s, first boot +
  CLI install ~3-4 min.
- Incremental PHP mutation: **0s** (bind mount, no rebuild; restart container
  only if opcache/config.php involved).

## Reset

`./tester-env reset` = `compose down -v` (containers + pgdata/moodledata
volumes; image kept) + `rm -f config.php`. Verified deterministic across two
consecutive full reset+deploy+seed+verify cycles (identical user/course/cm
ids and counts).

## Seed profile (tester-env-seed.php, idempotent by stable keys)

- Theme: Springfield Science Department, category `Science Department`
  (idnumber `SCI`).
- Users (all `Seedpass1!`, manual auth, confirmed): `teacher_maria`
  (Maria Santos), `student_james` (James Carter), `student_priya` (Priya Nair).
- Courses (visible, startdate fixed 2026-01-12 UTC): `BIO101`
  "Introduction to Biology" (forum `Cell Biology Q&A` + assignment
  `Mitosis Lab Report`, window 2026-01-12 09:00 -> 2026-02-02 23:59 UTC,
  grade 100), `CHEM101` "Chemistry Fundamentals" (only the default
  `Announcements` news forum -- intentional empty-course comparison).
- Enrolments (manual plugin): BIO101 = teacher(editingteacher) + 2 students
  (3 total); CHEM101 = teacher + student_james (2 total).
- Hidden category `Hidden Archive` (idnumber `HID`, visible 0) for the
  calendar-subscription permission oracle; `SCI` stays visible.
- Forum `Cell Biology Q&A`: average-of-ratings on a 5-point scale
  (gradebook item max 5) + fixed discussion `Chromatid puzzle` by
  student_james (2026-01-15 10:00 UTC) for rate/clear gradebook flows.
- RSS: teacher-owned feed `Biology Dept News` (static local fixture
  `public/tester-env-seed-rss.xml`, 3 fixed items, fetched by the server
  from `http://127.0.0.1/tester-env-seed-rss.xml`) + one `rss_client`
  block instance on the BIO101 course page (`course-view-*`, side-pre)
  bound to the feed. `curlsecurityblockedhosts` is emptied by seed
  (Moodle blocks loopback/private fetch hosts by default; this local
  test site needs none of that list) and the `rss_client` block plugin
  is enabled (disabled on fresh installs by core).
- Database activity `Lab Specimen Log` in BIO101: text fields `Specimen`
  + `Observation notes`, 3 approved entries (`Onion root tip slide` /
  `Chloroplast model` / `DNA extraction sample`, 2026-01-16 11:00 UTC+),
  default list/single/add/search templates generated.
- Expected verify counts: 3 seed users (ids 3,4,5 from clean install),
  courses ids 2,3, enrolments 3+2, BIO101 4 modules (news forum + Q&A
  forum + assignment + database), CHEM101 1 module.
- Credentials: admin/test (site admin), all seed users `Seedpass1!`.
- Static manifest: `scenarios/moodle/seed.manifest.json` (parent corpus repo).

## Browser smoke (acceptance, 2026-09-17)

- Canonical `http://172.17.0.1:8000` login renders; admin/test login ->
  Dashboard; BIO101 (forum Cell Biology Q&A + assignment Mitosis Lab Report
  due 2026-02-02) + CHEM101 (only Announcements) verified in GUI; state
  change admin City SmokeCity round-trip reverted clean; logout/login cycle
  OK (browser-checker PASS, reported via orchestrator).

## Caveats

- `GET /` returns 303 to `/login/index.php` for logged-out users (default
  forced-login frontpage) -- expected, not a failure.
- No mail service: `smtphosts` left empty to avoid delivery stalls
  (`enrol_try_internal_enrol` mail attempts log harmless `sendmail not
  found` noise).
- `config.php` is generated (gitignored by upstream); never commit it.
