# SHEHITA Enterprise Management System

A PHP + MySQL business management system (no framework), sold as a subscription to
multiple client companies from a single deployment.

`homepage.php` is the application shell; it loads each feature from `modules/<page>.php`
based on the `?page=` query parameter. Access is governed by role-based permissions
defined in `config.php`.

> For a full breakdown of every module and the database schema, see **[MODULES.md](MODULES.md)**.
> For deployment, see **[DEPLOY.md](DEPLOY.md)**.

---

## How multi-tenancy works

Every client company lives under its own path on the **same domain**:

```
https://system.shehita.co.tz/signup           <- a company creates its account
https://system.shehita.co.tz/acmetraders/     <- that company's portal
https://system.shehita.co.tz/arc/             <- another company's portal
https://system.shehita.co.tz/admin/           <- SHEHITA's own console
```

A path rather than a subdomain, so adding a company needs **no DNS record and no
certificate** — which is what makes instant signup possible.

**Each company gets its own MySQL database** (`shems_<slug>`), plus one shared control
database (`shehita_control`) holding the list of companies, their subscriptions, payments
and SHEHITA's staff logins.

That choice is the reason nothing inside `modules/` needed changing: those files use
`global $conn`, and `config.php` points `$conn` at the right company's database. One
company cannot read another's rows even if a query somewhere forgets a `WHERE` clause.

### The request path

```
.htaccess          strips /<slug>/ and passes the slug to PHP via REDIRECT_TENANT
bootstrap.php      looks the company up, opens a session scoped to it,
                   defines TENANT_DB, resolves the subscription
config.php         connects to TENANT_DB, builds/verifies that company's schema
homepage.php       checks login, checks subscription, includes the module
```

`bootstrap.php` is the first line of every entry point and **replaces** their
`session_start()` — the session has to be scoped to a company before it starts, or
signing into one portal would be visible in another.

### Branding

A company uploads a logo at signup and `lib/brand.php` reads the dominant brand colour out
of the image with GD, discarding near-white, near-black and near-grey pixels first (a logo
is mostly white background and black text; the literal most-common colour is useless). It
then regenerates the whole nine-step `--brown-900 … --brown-100` palette from that hue.

This works because the existing stylesheets already route ~296 colour references through
`var(--brown-*)`. `lib/theme.php` emits a second `:root` block after each page's own
styles, so the later rule wins and the entire UI re-colours without the original palettes
being deleted.

Pale logos (yellow, cyan) are darkened at the 900/800/700 steps until white text on them
clears WCAG AA, so the navbar stays readable. Companies can correct the auto-picked colour
in **Company Settings**.

---

## Subscriptions

Every company gets a **14-day free trial** the moment it is created. After that, access is
locked until SHEHITA records a payment.

`lib/subscription.php` holds **one pure resolver**, `subscription_resolve()`. The countdown
banner, the lockout, the admin console and the reminder emails all read it, so they can
never disagree about whether a company is active.

When a period lapses:
- ordinary staff are sent to `expired.php`;
- the company's **own administrator** can still sign in and reach `billing.php`, so they
  can always see what to pay.

Nothing is deleted. Everything returns the moment a payment is confirmed.

### Payment, by email

1. The company pays SHEHITA offline (bank transfer / Lipa namba).
2. Their admin presses **"I have paid"** on `billing.php` and enters the reference.
3. SHEHITA's billing addresses receive an email with a **one-click confirmation link** —
   openable straight from an inbox, on a phone, no login required.
4. Confirming extends the subscription, issues an invoice, and emails it to the company.

Only the SHA-256 of the confirmation token is stored, it expires after 7 days, and every
failure mode (forged, expired, already used) renders an identical page so the endpoint
cannot be probed. Activation is idempotent on `(method, reference)`, so a double-click
cannot extend twice.

Periods **stack**: paying while you still have time left adds to the end date rather than
resetting to today.

SHEHITA can also record a payment directly in the admin console.

---

## Running the app locally (Docker)

The project ships with a Docker setup (`Dockerfile` + `docker-compose.yml`) that runs
PHP + Apache and MySQL for you. You do **not** need PHP or MySQL installed on your machine.

**1. Start Docker Desktop** and wait until it says "Engine running" (30–60 seconds).

**2. Start the app** — in PowerShell, from this folder:
```powershell
cd D:\coding\ShehitaSystem
.\dev.ps1 start
```
The first run builds the image and takes a few minutes. After that it starts in seconds.

**3. Create a company** at **http://localhost:8080/signup.php** — company name, a logo, and
an administrator account. You are redirected to your new portal and the trial starts.

**4. SHEHITA's console** is at **http://localhost:8080/admin/**
(`admin@shehita.co.tz` / `changeme123`).

**5. Stop it when you're done** (keeps all your data):
```powershell
.\dev.ps1 stop
```

> There is no default company. The system starts empty; companies come from `/signup.php`.

### `dev.ps1` commands

| Command | What it does |
|---|---|
| `.\dev.ps1 start` | Start the app on http://localhost:8080 |
| `.\dev.ps1 stop` | Stop it, keeping all data |
| `.\dev.ps1 restart` | Stop then start |
| `.\dev.ps1 status` | Is it running? |
| `.\dev.ps1 logs` | Live logs (Ctrl+C stops watching, not the app) |
| `.\dev.ps1 mail` | Show the emails the system "sent" |
| `.\dev.ps1 test` | Run the end-to-end smoke test |
| `.\dev.ps1 rebuild` | Rebuild after editing the Dockerfile |
| `.\dev.ps1 reset` | **Delete all local data** and start fresh |

Data lives in a Docker volume (`shehita-dbdata`) and survives stop/start/rebuild. Only
`reset` destroys it.

> **Why a script instead of `docker compose`?** Docker Desktop does not always install the
> Compose plugin, and `docker compose` then fails with "unknown command". `dev.ps1` uses
> plain `docker` commands so it works either way. `docker-compose.yml` is still there and
> is equivalent if you do have Compose — production uses `docker-compose.prod.yml`.

### Reading the emails locally

`MAIL_DRIVER=log` writes every email to a file instead of sending it, **including the
links** — which is how you complete the payment flow on your own machine:

```powershell
.\dev.ps1 mail
```

The confirmation link from "I have paid" appears there under "Links in this email"; paste
it into the browser to confirm the payment as SHEHITA would from their inbox.

### Checking it still works

There is an end-to-end smoke test that drives the real HTTP endpoints — signup,
tenant isolation, the subscription gate, the emailed payment loop, and the assistant's
permission checks:

```powershell
.\dev.ps1 test
```

It creates two throwaway companies, exercises everything, and deletes them again. Run it
after any deploy and after touching `bootstrap.php`, `.htaccess`, `config.php` or the
subscription code.

---

## Troubleshooting

- **`docker: unknown command: docker compose`**
  → Docker Desktop installed without the Compose plugin. Use `.\dev.ps1` instead; it does
  the same job with plain `docker` commands.

- **"cannot find the file ...dockerDesktopLinuxEngine"**
  → Docker Desktop isn't running yet. Start it, wait for "Engine running", try again.

- **PowerShell says running scripts is disabled**
  → Allow local scripts for your user (one time):
  `Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`

- **Every company URL 404s**
  → `mod_rewrite` or `AllowOverride` is missing. Both are set up in the `Dockerfile`; if you
  changed it, rebuild with `docker compose up -d --build`.

- **Port 8080 already in use**
  → Change `$Port` near the top of `dev.ps1` (it also updates `APP_BASE_URL`, which is what
  emailed links use), then `.\dev.ps1 restart`.

- **The AI assistant bubble doesn't appear**
  → It renders nothing unless `ANTHROPIC_API_KEY` is set *and* `vendor/` exists. Add the
  key to the `docker run` block in `dev.ps1`, then `.\dev.ps1 restart`.

- **Database access**
  → MySQL is exposed on host port **3307** for inspection with a database client
  (`root` / `rootpass` locally). You will see one database per company.

---

## How first-run setup works

There is no `.sql` file. Schema is created in code and is self-healing:

- `lib/schema.php` defines every table a company has, and records a schema version in that
  company's database. Bumping `TENANT_SCHEMA_VERSION` re-runs the pass for every company on
  their next request.
  **Adding or changing a column also needs a step in `run_tenant_migrations()`** — the
  `CREATE TABLE IF NOT EXISTS` statements are a no-op on a table that already exists, so a
  version bump on its own would change nothing while recording itself as applied.
- `lib/control_db.php` does the same for the platform's own tables and seeds the default
  subscription packages and the first SHEHITA administrator.

---

## The AI assistant

A chat bubble on every page (`partials/ai-assistant.php` → `ai/chat.php`) that explains how
the system works and answers questions about that company's own records, using
`claude-opus-5`.

It is **read-only** and **permission-checked**: every tool in `lib/ai_tools.php` re-runs
`canView()` against the signed-in user's role before returning anything, so the assistant
cannot be used to read past the permissions table. The model never writes SQL — each tool
is one fixed parameterised query.

Set `ANTHROPIC_API_KEY` to enable it. Usage is capped per company per month
(`tenants.ai_monthly_cap`) and SHEHITA can turn it off per company.

---

## Layout

```
bootstrap.php        tenant resolution, session scoping, subscription state
config.php           DB connection for the resolved company + permission helpers
signup.php           create a company (public)
billing.php          subscription & payment (company admin)
expired.php          lapsed-subscription notice (staff)
invoice-view.php     customer-facing subscription invoice

lib/
  schema.php         the tables every company has
  control_db.php     the platform's own tables
  subscription.php   the one subscription resolver + activation
  brand.php          brand colour extraction and palette generation
  theme.php          per-company CSS and logo resolution
  provision.php      creating and destroying companies
  mailer.php         email delivery (Resend / SMTP / log)
  email_templates.php
  invoice_render.php
  ai_tools.php       what the assistant may read
  util.php

admin/               SHEHITA's console (separate login)
ai/chat.php          assistant endpoint
cron/                daily notices, backups, adopting an existing database
modules/             the 17 feature modules (unchanged by multi-tenancy)
partials/            the assistant panel
tests/smoke.php      end-to-end smoke test
dev.ps1              local start/stop/test helper (Windows)
```
