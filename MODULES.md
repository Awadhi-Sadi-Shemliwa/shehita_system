# SHEHITA Enterprise Management System — Modules & Data Model

A plain PHP (no framework) + MySQL business management system, sold as a subscription to
multiple client companies from one deployment. `homepage.php` is the application shell: it
renders the top navbar and includes `modules/<page>.php` based on the `?page=` query
parameter. Every module is gated by the role-based access control (RBAC) helpers defined in
`config.php` (`canView/canAdd/canEdit/canDelete`).

`role_id = 1` ("Super Admin") implicitly has full access — **within its own company**.
SHEHITA's own staff are a separate identity in the control database entirely; see
`admin/`.

**Every company has its own database.** The tables described below exist once per company
in `shems_<slug>`, and none of them carries a company id, because the isolation is at the
database level. `bootstrap.php` decides which database `$conn` points at before any module
runs, which is why nothing in `modules/` needed changing when multi-tenancy was added.

There is no default login. Companies — and their first administrator — are created at
`/signup.php`. See [README.md](README.md) for the architecture.

---

## Core / Auth files (project root)

| File | Purpose |
|------|---------|
| `bootstrap.php` | **First line of every entry point.** Resolves which company the URL belongs to, opens a session scoped to that company (replacing `session_start()`), defines `TENANT_DB`, and resolves the subscription into `$SUBSCRIPTION`. |
| `index.php` | Entry point. Redirects to the company's `login.php`, or to `/signup.php` when the URL names no company. |
| `config.php` | Connects to the resolved company's database, verifies its schema via `lib/schema.php`, and defines the RBAC helpers. Returns the `$conn` mysqli object. |
| `login.php` | Authenticates a user, sets session vars, loads permissions into the session. |
| `logout.php` | Destroys the session and expires the company-scoped cookie. |
| `forgotpassword.php` | Password recovery via the user's security question/answer. |
| `aboutus.php` | Public informational page. |
| `homepage.php` | Authenticated shell: top navbar, 30-min idle timeout, **the subscription gate**, the renewal banner, the module loader, permission checks, and the AI assistant. |
| `heartbeat.php` | Keeps the session alive (AJAX ping) so active users aren't timed out. |
| `signup.php` | Public. Creates a company: its database, its administrator, its logo and its brand palette. |
| `billing.php` | The company administrator's subscription page. Reachable when the subscription has lapsed. |
| `expired.php` | What ordinary staff see once a subscription has lapsed. |
| `invoice-view.php` | Customer-facing subscription invoice, opened by emailed token or by the company admin. |

## Platform files

| Path | Purpose |
|------|---------|
| `lib/schema.php` | The definition of every table a company has, plus the schema version that drives migrations across all companies. |
| `lib/control_db.php` | The platform's own tables: companies, packages, payments, invoices, SHEHITA logins, audit. |
| `lib/subscription.php` | `subscription_resolve()` — the single source of truth for whether a company is active — plus activation, which stacks periods and is idempotent on `(method, reference)`. |
| `lib/brand.php` | Reads a brand colour out of an uploaded logo and generates the nine-step palette, with a contrast guard. |
| `lib/theme.php` | Emits the per-company `:root` override and resolves the logo. |
| `lib/provision.php` | Creating a company (with rollback) and destroying one. |
| `lib/mailer.php`, `lib/email_templates.php` | Every email in the subscription flow. |
| `lib/invoice_render.php` | The subscription invoice document (A4 print CSS, no PDF library). |
| `lib/ai_tools.php` | The complete, read-only, permission-checked surface the AI assistant can reach. |
| `admin/` | SHEHITA's console: companies, payments, packages, settings, and the emailed one-click payment confirmation. |
| `ai/chat.php` | The assistant endpoint. |
| `cron/subscription-notices.php` | Daily trial/renewal reminders. Idempotent. |
| `cron/backup.sh` | Nightly dump of every company's database plus uploads. |
| `cron/import-tenant.php` | Adopts an existing installation's database as a company (used for ARC). |

---

## Modules (`modules/`)

| Module (`?page=`) | Purpose | Primary table(s) |
|-------------------|---------|------------------|
| `home` | Dashboard landing page with KPI metrics and unassigned-projects notifications. Validates required tables exist. | *(reads many)* |
| `overview` | Executive dashboard combining contracts (projects) and operations: financial KPIs, charts, date-range filtering. | *(reads `projects`, `operations`)* |
| `projects` | Contract management with financial tracking (value, tax, commission, costs, profit). Auto contract numbers (`CON00001`), status auto-expiry. | `projects` |
| `operations` | Operations / quality management tied to contracts. Project Group → Category dependent dropdowns, staff assignment, recurring durations. | `operations` |
| `status` | Tracks active operations: milestones, status actions, notes; view-invoice modal. | *(reads `operations`)* |
| `customer-management` | CRUD for clients/customers (TIN, VRN, business type, contacts). | `customers` |
| `invoice` | Professional invoice generation/printing (A4), VAT 18%, live calculations. | `invoices` |
| `categories` | CRUD for categories; each belongs to a project group. | `categories` |
| `projectgroup` | CRUD for project groups (top of the group → category hierarchy). | `projectgroup` |
| `user-management` | CRUD for users, profile images, role/department assignment. Protects admin (ID 1) & self-deletion. | `users` |
| `departments` | CRUD for departments. Protected default "Administrator" (ID 1). | `departments` |
| `roles` | CRUD for roles, each tied to a department. Protected default "Super Admin" (ID 1). | `roles` |
| `permissions` | Assigns per-module `can_view/add/edit/delete` flags to roles. Super Admin auto-granted. | `permissions` |
| `profile` | Logged-in user edits their OWN profile, password, security Q&A. | *(updates `users`)* |
| `company-settings` | Single-record company info (name, address, TIN/VRN, currency, logo). | `company_settings` |
| `systemsettings` | Global key/value configuration (incl. English/Swahili language switching). | `system_settings` |

Common cross-cutting features baked into most modules: CSRF protection on forms,
English/Swahili translations, search/filter/pagination, permission-gated action buttons,
and auto-reset of `AUTO_INCREMENT` when a table is emptied.

---

## Data model

### Tables and key columns

| Table | Key columns | Foreign keys |
|-------|-------------|--------------|
| `departments` | id, name, description, status | — |
| `roles` | id, name, department_id, status, description | department_id → departments |
| `users` | id, name, email (unique), password (hashed), phone, address, profile_image, department_id, role_id, status, security_question, security_answer | department_id → departments (SET NULL), role_id → roles (SET NULL) |
| `permissions` | id, role_id, department_id, module_name, can_view, can_add, can_edit, can_delete | role_id → roles (CASCADE), department_id → departments (CASCADE); unique (role_id, module_name) |
| `projectgroup` | id, name, status | — |
| `categories` | id, projectgroup_id, name, status | projectgroup_id → projectgroup (CASCADE) |
| `customers` | id, customer_name, contact_person, tin_number, vrn_number, address, email, type_of_business, status | — |
| `company_settings` | id, company_name, company_address, company_email, company_phone, company_tin, vrn_number, currency_symbol, logo_url | — (single-row table) |
| `system_settings` | id, setting_key (unique), setting_value, setting_type, display_name, description, options (JSON), sort_order | — |
| `projects` | id, contract_number (unique), client_id, effective/end dates, contract_value, tax, commission, cost_of_project, staff_cost, overhead_cost, number_of_staff_allocated, target/actual/diff profit, status, created_by | client_id → customers (RESTRICT) |
| `invoices` | id, invoice_number (unique), customer_id, dates, particulars, quantity, rate, subtotal, vat, total, bank info, status, created_by | customer_id → customers (RESTRICT) |
| `operations` | id, invoice_id (unique), contract_number, project_group_id, category_id, duration_type, start/end dates, status, invoice_data (JSON), assigned_staff (JSON), created_by | project_group_id → projectgroup (RESTRICT), category_id → categories (RESTRICT) |

### Relationship / dependency graph

```
departments
  ├── roles ──────────────── users
  │     └── permissions
projectgroup
  └── categories
                 \
customers          operations  (operations → projectgroup + categories)
  ├── projects
  └── invoices

company_settings   (standalone)
system_settings    (standalone)
```

### Correct creation order (parents before children)

Because of the foreign keys above, tables **must** be created in this order:

1. `departments`
2. `roles`              *(→ departments)*
3. `users`             *(→ departments, roles)*
4. `permissions`       *(→ roles, departments)*
5. `projectgroup`
6. `categories`        *(→ projectgroup)*
7. `customers`
8. `company_settings`
9. `system_settings`
10. `projects`         *(→ customers)*
11. `invoices`         *(→ customers)*
12. `operations`       *(→ projectgroup, categories)*

---

## Known structural issue (addressed by the centralization change)

Historically each module ran its own `CREATE TABLE IF NOT EXISTS` on first visit. Because
of the foreign-key dependencies, opening a child module (e.g. `projects`) before its parent
(`customers`) caused the `CREATE TABLE` to fail and the page to `die()`. This made a fresh
deployment fragile and dependent on the order an admin happened to click through the menus.

The fix (see `config.php`) is to create **all** tables centrally, in the dependency order
above, at bootstrap — so the schema is always complete regardless of navigation order. The
duplicate/leftover `CREATE TABLE` blocks that previously lived in the modules are redundant
once this runs, but remain harmless (`IF NOT EXISTS`).
