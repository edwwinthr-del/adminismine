# AdminisMine — Finance Operations

An internal finance and operations system for **AdminisMine DOO**, replacing the workbook that the company runs
on today. It covers supplier debt, client receivables, bank and cash movements,
workers and payroll also attendance and daily earned pay, mining production,
machines, customs paperwork, worker housing, travel and loans — with an audit
trail, three interface languages and an LLM assistant that can never write to a
record without a human confirming it.

- `backend/` — Laravel 13 REST API (PHP 8.5)
- `frontend/` — Next.js 16 App Router client (React 19, TypeScript, Tailwind v4)
- `docker-compose.yml` — PostgreSQL 16 + Redis 7 for local development

The full product specification lives in
[`PROJECT_LLM_APP_PROMPT.md`](PROJECT_LLM_APP_PROMPT.md); working notes for
contributors are in [`CLAUDE.md`](CLAUDE.md) and
[`frontend/AGENTS.md`](frontend/AGENTS.md).

---

## Requirements

| Tool | Version | Notes |
|------|---------|-------|
| PHP | 8.5 | with `gd` enabled (PhpSpreadsheet needs it) |
| Composer | 2.x | |
| Node.js | 20+ | |
| Docker | any recent | for PostgreSQL + Redis |

## Setup

```bash
# 1. Databases. Postgres is published on host port 5433, not 5432 —
#    a native Postgres commonly already holds 5432.
docker compose up -d --wait

# 2. Backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed        # schema + roles/permissions + Super Admin
php artisan serve --port=8000

# 3. Frontend (second terminal)
cd frontend
npm install
cp .env.example .env.local
npm run dev
```

Then open <http://localhost:3000> and sign in.

**Seeded login:** `superadmin@test.test` / `password` (Super Admin).
Change it before anything resembling production.

On Windows, prefix PHP commands with `XDEBUG_MODE=off` (PowerShell:
`$env:XDEBUG_MODE='off'`) to skip Xdebug's step-debug timeout on every
invocation.

## Environment variables

### `backend/.env`

| Variable | Default | What it does |
|----------|---------|--------------|
| `APP_KEY` | — | Required. Generate with `php artisan key:generate`. |
| `APP_URL` | `http://localhost:8000` | Public URL of the API. |
| `APP_COMPANY_NAME` | `AdminisMine DOO` | Seeds the company name; Admins edit it in Settings afterwards. |
| `APP_BASE_CURRENCY` | `EUR` | The accounting currency. Everything converts to it. |
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `5433` | Matches `docker-compose.yml`. Keep the literal IP — see "Local performance" below. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `adminismine` / `adminismine` / `secret` | |
| `DB_SSLMODE` | `disable` | Laravel's default is `prefer`, which negotiates TLS on every connect — ~10 ms per request for nothing when Postgres is a Docker container on loopback. Leave at `prefer` if the database is ever remote. |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | Backs the queue and cache. |
| `QUEUE_CONNECTION` | `redis` | Imports and scans run as queued jobs. |
| `FILESYSTEM_DISK` | `local` | Attachments live on a **private** disk and are only served through the authenticated download route. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000` | Frontend origin allowed to authenticate. |
| `FRONTEND_URL` | `http://localhost:3000` | Used for CORS. |
| `EXCHANGE_RATE_PROVIDER` | `frankfurter` | Rate source for the automatic TRY pull. |
| `EXCHANGE_RATE_URL` | `https://api.frankfurter.app` | |
| `EXCHANGE_RATE_API_KEY` | — | Only if the chosen provider needs one. |
| `OPENAI_API_KEY` | — | Required for the assistant. Without it the assistant returns an error; every other module works. |
| `OPENAI_MODEL` | `gpt-4o-mini` | Model the assistant uses. |

### `frontend/.env.local`

| Variable | Default | What it does |
|----------|---------|--------------|
| `NEXT_PUBLIC_API_URL` | `http://127.0.0.1:8000/api` | Base URL of the API. **Not `localhost`** — see below. |

## Local performance

Three settings dominate how fast the app feels in development. None of them are
application code, and all three were measured rather than guessed — a list page
went from ~1450 ms to ~450 ms end to end after fixing them.

**1. Never write `localhost` in a URL or DB host on Windows.** It resolves to
`::1` first, while `php artisan serve` and Docker's published ports both bind
IPv4 only. Every connection then stalls failing over from IPv6 to IPv4:

| Target | via `localhost` | via `127.0.0.1` |
|---|---|---|
| API TCP connect | **206 ms** | 2 ms |
| Postgres PDO connect | **2060 ms** | 22 ms |

**2. Keep Xdebug's mode off unless you are actually debugging.** `xdebug.mode=debug`
instruments every function call whether or not a debugger is attached — it cost
~300 ms on *every* request, including ones that touch no database. In
`C:\xampp\php\php.ini`:

```ini
xdebug.mode=off
xdebug.start_with_request=trigger
```

Turn it on per process when you need it — the env var overrides the ini file, so
nothing is lost:

```bash
XDEBUG_MODE=debug php artisan serve          # PowerShell: $env:XDEBUG_MODE='debug'
XDEBUG_MODE=debug php artisan test --filter SomeTest
```

**3. `php artisan serve` handles one request at a time on Windows.** PHP's
built-in server needs `fork()` for concurrency, which Windows does not have
(`PHP_CLI_SERVER_WORKERS` prints "forking is not supported on this platform").
Every request a page makes is therefore *added*, not overlapped — which is why
per-request overhead matters so much more here than the queries do. If serialized
requests become the limit, run the API under a real SAPI (`php-fpm` behind nginx,
Laravel Octane, or the Docker image) instead of tuning the app.

Optionally, `php artisan config:cache && php artisan route:cache` takes a further
~25 ms off each request. It is **not** on by default because a cached config
ignores later `.env` edits and a cached route table ignores new routes — a
confusing failure while the app is still being built. Run `config:clear` and
`route:clear` before you change either.

## Background work

Two jobs are scheduled (`backend/routes/console.php`) and need a running
scheduler — `php artisan schedule:work` in development, cron in production:

| When | Job | What it does |
|------|-----|--------------|
| 06:00 daily | `SyncExchangeRatesJob` | Pulls the day's TRY rate. |
| 06:30 daily | `ScanNotificationsJob` | Recomputes notifications from the modules' own scopes. |

Both can be triggered by hand: `php artisan notifications:scan`, or
`POST /api/exchange-rates/sync` and `POST /api/notifications/scan` from the app.
Queued work needs a worker: `php artisan queue:work`.

## Using the app

The first screen after login is the dashboard; everything else hangs off the
sidebar, and each item only appears if your permissions allow it.

- **Importing the workbook.** *Imports* → pick what you are importing → download
  the template generated for it → fill it in → upload. Rows are validated one by
  one and shown as a preview; nothing reaches the real tables until you approve
  the batch, and invalid rows are reported rather than failing the whole file.
  Omitting the entity uploads the original GLOBAL MINE workbook whole, matched
  sheet by sheet.
- **Money.** Payables, Receivables, Bank & Cash. Balances are always computed
  from the payment records — there is no editable "remaining" field anywhere.
  Invoices stay correctable after they are paid: fix the wrong line, never book
  an offsetting entry.
- **Currency.** EUR is the accounting currency. A foreign-currency row keeps its
  original amount, the EUR value, and the rate that produced it. *Exchange
  Rates* shows what the app will convert with and lets an authorised user pin a
  manual rate — which always requires a recorded reason.
- **People.** Workers, Salary Payments, Masters, Attendance, Worker Needs.
  Attendance only reaches payroll once it is approved.
- **Operations.** Mining Production, Machines, Customs Documents, and the work
  structure: *Mines* (the deposit), *Projects* (the billed work) and *Worksites*
  (where people clock in). Attendance, production and machines are filterable by
  any of the three.
- **Reports.** Nineteen reports, each exportable as **Excel or PDF**; the PDFs
  carry the company name, title, date range, who generated it and a
  signature/stamp block where one is wanted.
- **Assistant.** Ask in Serbian, Turkish or English. It can search, summarise
  and *prepare* records — anything that would write is shown as a proposal you
  confirm or reject, and confirming is the only path to a saved record.
- **Audit log.** Every recorded action, read-only, filterable by actor, record
  type and date.
- **Roles & permissions.** Create, edit, clone, assign and delete custom roles;
  grant a user extra permissions on top of their roles. Core roles are protected
  from deletion.

The language switcher (top right) changes the interface only — stored data is
language-neutral (`paid`, `unpaid`, `income`, …) and translated at display time,
so switching language never rewrites a financial record.

## Testing

```bash
# backend/
php artisan test                       # full suite, on in-memory SQLite
php artisan test --filter <TestName>    # one test or class
./vendor/bin/pint                       # format

# frontend/
npm run build                           # production build; also type-checks
npm run lint
```

Migrations must stay portable: the suite runs on SQLite while real data lives on
PostgreSQL, so verify schema changes with `php artisan migrate` against Postgres
as well.

## Architecture notes

Things worth knowing before changing code — the reasoning behind each is in
[`CLAUDE.md`](CLAUDE.md):

- **Balances are never stored.** Every remaining/paid/outstanding figure is
  computed from its records.
- **The LLM never silently writes.** Select context → structured JSON → validate
  → preview → user confirms → save → audit log.
- **Everything important is auditable.** Financial tables carry
  `created_by/updated_by/source/notes`; actions land in a central activity log.
- **Access is roles *plus* granular permissions.** Never gate a feature on a
  role name.
- **Settlements are one polymorphic `payments` table**, which is what lets an
  invoice, a rent charge, a ticket or a loan repayment all be matched against a
  bank movement.
- **Attachments are one polymorphic table** (`file_attachments`) shared by every
  module — never a per-module attachment table.
- **Reads are cached, writes invalidate them.** Any non-GET refreshes every
  mounted resource, which is what keeps the dashboard honest.
