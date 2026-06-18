# SHEHITA Enterprise Management System — Modules & Data Model

A plain PHP (no framework) + MySQL business management system. `homepage.php` is the
application shell: it renders the top navbar and includes `modules/<page>.php` based on
the `?page=` query parameter. Every module is gated by the role-based access control
(RBAC) helpers defined in `config.php` (`canView/canAdd/canEdit/canDelete`). Role
`role_id = 1` ("Super Admin") implicitly has full access to everything.

Default login: the admin account seeded by `config.php` from the
`ADMIN_DEFAULT_EMAIL` / `ADMIN_DEFAULT_PASSWORD` environment variables.

---

## Core / Auth files (project root)

| File | Purpose |
|------|---------|
| `index.php` | Entry point. Redirects to `login.php`. |
| `config.php` | DB connection, schema bootstrap, default seed data (admin dept/role/user), and the RBAC helper functions. Returns the `$conn` mysqli object. |
| `login.php` | Authenticates a user, sets session vars, loads permissions into the session. |
| `logout.php` | Destroys the session. |
| `forgotpassword.php` | Password recovery via the user's security question/answer. |
| `aboutus.php` | Public informational page. |
| `homepage.php` | Authenticated shell: top navbar, dropdown menus, 30-min idle timeout, dynamic module loader, permission checks. |
| `heartbeat.php` | Keeps the session alive (AJAX ping) so active users aren't timed out. |

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
