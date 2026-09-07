# AureusERP — Local Setup Guide (this machine)

Complete guide for the AureusERP installation at `~/Projects/aureuserp`,
including credentials, service management, and every command used to set it up.

> **Current state:** the app runs on **PostgreSQL 18** with all 19 plugins
> installed. The original MariaDB fallback has been fully removed.

---

## 1. Quick Reference

### Access

| Item | Value |
|---|---|
| **Admin panel URL** | http://aureuserp.test/admin/login (Laravel Valet) |
| **Admin email** | `admin@aureuserp.local` |
| **Admin password** | `Admin@1234` |
| **App URL** | http://aureuserp.test |

### Database (PostgreSQL 18)

| Item | Value |
|---|---|
| **Connection** | `pgsql` |
| **Host / Port** | `127.0.0.1` / `5432` |
| **Database** | `aureuserp` |
| **App user** | `aureus` |
| **Password** | `aureus_local_pg` |
| **Service** | `brew services start postgresql@18` |

### Paths

| Path | Purpose |
|---|---|
| `~/Projects/aureuserp/` | App repository |
| `~/Projects/aureuserp/.env` | Laravel config (`DB_CONNECTION=pgsql`) |
| `~/.config/valet/Sites/aureuserp` | Valet symlink → `~/Projects/aureuserp` |
| `~/.config/valet/Nginx/aureuserp.test` | Valet nginx vhost (PHP 8.4 isolated) |
| `~/.composer/vendor/bin/valet` | Valet CLI (also `export PATH="$HOME/.composer/vendor/bin:$PATH"`) |

---

## 2. Prerequisites (already installed on this machine)

| Tool | Version used | Notes |
|---|---|---|
| PHP | **8.4.21** (`/opt/homebrew/opt/php@8.4`) | Required. See warning below. Needs `pdo_pgsql` (present) |
| Composer | 2.8.12 | Also hosts the global Valet install |
| PostgreSQL | 18.1 (`/opt/homebrew/opt/postgresql@18`) | **Current DB** |
| Valet | 4.12 (`~/.composer/vendor/bin/valet`) | **Web server**: nginx + dnsmasq + per-site PHP-FPM |
| Node.js | 24.x | For frontend assets (Vite) |

> ⚠️ **Do not use the default `php` on this machine** — it is PHP 8.5, which is
> too new for a locked dependency (`phpoffice/phpspreadsheet` requires PHP < 8.5)
> and `composer install` fails on it. Always use `/opt/homebrew/opt/php@8.4/bin/php`.

---

## 3. Step-by-Step Setup

### Step 1 — Clone the repository

```bash
cd ~/Projects
git clone https://github.com/aureuserp/aureuserp.git
cd aureuserp
```

### Step 2 — Install PHP dependencies

```bash
cd ~/Projects/aureuserp
/opt/homebrew/opt/php@8.4/bin/php $(which composer) install --no-interaction
```

### Step 3 — PostgreSQL setup

```bash
# Start the service (starts at login, via Homebrew)
brew services start postgresql@18

PSQL=/opt/homebrew/opt/postgresql@18/bin/psql
$PSQL -d postgres -c "CREATE USER aureus WITH PASSWORD 'aureus_local_pg';"
$PSQL -d postgres -c "CREATE DATABASE aureuserp OWNER aureus;"
```

### Step 4 — Configure the environment

```bash
cd ~/Projects/aureuserp
cp .env.example .env
sed -i '' \
  -e "s/^APP_NAME=.*/APP_NAME=AureusERP/" \
  -e "s|^APP_URL=.*|APP_URL=http://aureuserp.test|" \
  -e "s/^# DB_HOST=.*/DB_HOST=127.0.0.1/" \
  -e "s/^# DB_PORT=.*/DB_PORT=5432/" \
  -e "s/^# DB_DATABASE=.*/DB_DATABASE=aureuserp/" \
  -e "s/^# DB_USERNAME=.*/DB_USERNAME=aureus/" \
  -e "s/^# DB_PASSWORD=.*/DB_PASSWORD=aureus_local_pg/" \
  -e "s/^DB_CONNECTION=.*/DB_CONNECTION=pgsql/" .env
/opt/homebrew/opt/php@8.4/bin/php artisan key:generate
/opt/homebrew/opt/php@8.4/bin/php artisan config:clear
```

Effective settings: `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`,
`DB_DATABASE=aureuserp`, `DB_USERNAME=aureus`, `SESSION_DRIVER=database`,
`CACHE_STORE=database`, `QUEUE_CONNECTION=database`.

The repo also ships `.env.pgtest` as a PostgreSQL reference (it expects a
`postgres/postgres` superuser on port 5433 — we use our own dedicated user on
the default 5432 instead).

### Step 5 — Run the ERP installer

Installs migrations, core seeders, Shield roles & permissions, and the admin
account:

```bash
cd ~/Projects/aureuserp
/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=1G artisan erp:install \
  --force --no-interaction \
  --admin-name="Admin" \
  --admin-email="admin@aureuserp.local" \
  --admin-password="Admin@1234" \
  --currency=USD
```

> Drop `--force` to run interactively. `--country=US` may be used instead of
> `--currency` to derive the default currency from a country.

### Step 6 — Install all plugins

```bash
PHP=/opt/homebrew/opt/php@8.4/bin/php
cd ~/Projects/aureuserp
for plugin in accounts inventories employees contacts projects website \
              maintenance invoices payments recruitments time-off sales \
              purchases manufacturing barcode timesheets blogs accounting; do
  $PHP -d memory_limit=1G artisan $plugin:install --no-interaction
done

# Regenerate admin-panel permissions once at the end
$PHP -d memory_limit=1G artisan shield:generate --all --option=permissions --panel=admin
```

> ⚠️ **Memory limit:** plugins with deep dependency chains (`purchases`,
> `manufacturing`) exhaust the default 128M limit — always use
> `-d memory_limit=1G` for plugin installs.
>
> ⚠️ If an install logs `Permission refresh failed:` (the Shield subprocess
> doesn't inherit the memory limit), just re-run `shield:generate` as above.

Dependency chains (from `plugin_dependencies`): `sales → invoices+payments →
accounts → products`; `purchases → invoices`; `manufacturing →
inventories+products`; `recruitments/time-off → employees`; `timesheets →
projects`; `blogs → website`. Each `plugin:install` auto-installs its missing
dependencies recursively; already-installed ones are skipped under
`--no-interaction`.

Plugin install/uninstall commands are **hidden from `artisan list`** but exist.
List them with:

```bash
$PHP artisan tinker --execute="echo collect(\Artisan::all())->keys()->filter(fn(\$c) => str_contains(\$c, ':install'))->implode(\"\n\");"
```

Current state on this machine: **19/19 plugins installed** (accounting,
accounts, barcode, blogs, contacts, employees, inventories, invoices,
maintenance, manufacturing, payments, products, projects, purchases,
recruitments, sales, time-off, timesheets, website). Install state lives in
the `plugins` table:

```bash
psql -d aureuserp -c "SELECT name, is_installed FROM plugins ORDER BY is_installed DESC, name;"
```

Core system plugins (Analytics, Chatter, Fields, Security, Support, Table View)
are installed by `erp:install`. (`partners` ships as part of `contacts`; there
is no separate `partners:install` command.)

### Step 7 — Frontend assets (optional)

Composer's post-install already published built assets. For development or
after frontend changes:

```bash
cd ~/Projects/aureuserp
npm install
npm run build      # production build (Vite)
# or: npm run dev  # dev server with HMR
```

### Step 8 — Serve via Laravel Valet (http://aureuserp.test)

The app is served by **Valet** (nginx + PHP-FPM) — no LaunchAgents, no
`php artisan serve`, and the broken-pipe/Livewire-JSON bug class is gone.
Valet itself was installed once with `valet install` (needs sudo — run it in
your own terminal). Site setup (needs sudo for Valet's internals, run in your
terminal too):

```bash
export PATH="$HOME/.composer/vendor/bin:$PATH"
cd ~/Projects/aureuserp
valet link aureuserp        # serves at http://aureuserp.test
valet isolate php@8.4 --site=aureuserp   # ⚠️ REQUIRED: app needs PHP < 8.5
```

Sudo-free, scriptable operations:

```bash
# Stop / start the nginx+dnsmasq services (user domain, no sudo needed)
valet stop && valet start

# Unlink the site
valet unlink aureuserp
```

> `valet isolate` gives this site its own PHP 8.4-FPM pool; the system PHP
> 8.5 keeps serving everything else. Do **not** skip it — the Composer
> platform check makes the app fatal under 8.5.

### Step 9 — Verify

```bash
# Login page responds
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://aureuserp.test/admin/login

# DB connectivity + counts
psql -d aureuserp -t -c "SELECT 'users: ' || COUNT(*) FROM users;"
psql -d aureuserp -t -c "SELECT 'plugins: ' || COUNT(*) FILTER (WHERE is_installed) || '/' || COUNT(*) FROM plugins;"
```

Then open http://aureuserp.test/admin/login and sign in with the credentials
from §1.

---

## 4. Database Commands (PostgreSQL)

```bash
PSQL=/opt/homebrew/opt/postgresql@18/bin/psql

# Interactive shell (password comes from ~/.pgpass or PGPASSWORD)
PGPASSWORD=aureus_local_pg $PSQL -h 127.0.0.1 -U aureus -d aureuserp

# One-off queries
$PSQL -d aureuserp -c "SELECT COUNT(*) FROM users;"
$PSQL -d aureuserp -t -c "SELECT 'plugins: ' || COUNT(*) FILTER (WHERE is_installed) || '/' || COUNT(*) FROM plugins;"

# Dump / restore
PGPASSWORD=aureus_local_pg /opt/homebrew/opt/postgresql@18/bin/pg_dump \
  -h 127.0.0.1 -U aureus aureuserp > backup.sql
PGPASSWORD=aureus_local_pg $PSQL -h 127.0.0.1 -U aureus aureuserp < backup.sql

# Service status
brew services list | grep postgresql
```

---

## 5. Service Management

| Service | How it runs | Start / Stop | Log |
|---|---|---|---|
| PostgreSQL 18 (5432) | `brew services` | `brew services start/stop postgresql@18` | `brew services info postgresql@18` |
| Web server (80) | **Valet** — nginx (brew) + per-site PHP 8.4-FPM pool | `valet stop` / `valet start` | `~/.config/valet/Log/` |

```bash
# Web server (Valet)
valet status
tail -f ~/.config/valet/Log/nginx-error.log 2>/dev/null

# Health checks
brew services list | grep -E "nginx|dnsmasq|postgresql"
curl -s -o /dev/null -w "HTTP %{http_code}\n" http://aureuserp.test/admin/login
```

> **Why Valet instead of `php artisan serve`?** The built-in server's
> per-request process reload produced `Broken pipe` notices that corrupted
> Livewire JSON responses (table actions/pagination on the Plugins page
> failed). nginx+PHP-FPM eliminates that entire bug class, gives per-site PHP
> version isolation (8.4 here vs system 8.5), and needs no hand-maintained
> LaunchAgents.

---

## 6. Daily Use

### Artisan (always via PHP 8.4)

```bash
cd ~/Projects/aureuserp
PHP=/opt/homebrew/opt/php@8.4/bin/php

$PHP artisan tinker
$PHP artisan migrate
$PHP artisan db:seed
$PHP artisan route:list
$PHP artisan test
$PHP artisan queue:work      # queues use the database driver
```

### Uninstalling a plugin

```bash
$PHP artisan inventories:uninstall   # ⚠️ drops the plugin's tables and data — back up first
```

---

## 7. Customization

### Branding (deep teal, applied)

The panel's colors and logos are **database-driven** via `BrandSettings`, applied
by the `ApplyBrandSettings` middleware (a hex seed generates the full OKLCH
palette). Edit visually in the admin UI: **Settings → Branding** (logo light/dark,
favicon, logo height, and 6 color pickers).

Applied values on this machine: primary `#0F766E` (deep teal), gray `#52525B`,
danger `#DC2626`, info `#0284C7`, success `#16A34A`, warning `#D97706`.

### Roles & demo users (applied)

Created by `scripts/setup_customization.php` (idempotent — safe to re-run):

| Role | Permissions | Scope |
|---|---|---|
| Admin | 1,558 | Everything (installed by `erp:install`) |
| Salesperson | 191 | CRM + Sales full, Invoices/Accounting reports read |
| Warehouse | 200 | Inventory + Products full, Sale/Purchase/Manufacturing read |
| Accountant | 343 | Accounting + Invoices full, commercial modules read |
| HR Manager | 173 | Employees, Recruitment, Time-off, Timesheets full, Projects read |

Demo users (password `Demo@1234`): `sales@erp.local`, `warehouse@erp.local`,
`accountant@erp.local`, `hr@erp.local`.

Re-apply or adjust:

```bash
cd ~/Projects/aureuserp
/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=1G scripts/setup_customization.php
```

Edit the `$roleSpecs` array in the script to change scopes. Permissions follow
the Shield naming scheme `<verb>_<module>_<resource>...` (verbs: `view_any`,
`view`, `create`, `update`, `delete*`, `restore*`, `force_delete*`, `reorder`,
`page`, `widget`); names containing `_manage_` are admin-only surfaces and are
excluded from business roles. Roles can also be fine-tuned per-permission in
the UI: **Settings → Roles & Permissions**.

### Custom reports (app namespace)

Two reports implemented (both `Accounting → Reporting → Audit Reports`):

- **Sales by Customer** (`/admin/accounting/reporting/sales-by-customer`)
  with a per-customer **drill-down**: clicking a customer opens
  `/admin/accounting/reporting/customer-order-lines?partner=ID&from=…&to=…&state=…`
  (KPI cards + order lines grouped by order; hidden from navigation — reached
  only via the summary's customer links).
- **Sales by Product** (`/admin/accounting/reporting/sales-by-product`) —
  aggregates `sales_order_lines` by product (orders, qty, untaxed, total) with
  date-range / order-state / product / category filters and CSV export.

Demo data backing these reports: 6 products in `products_products` (seeded
via SQL; `type` is `goods`|`service`, requires `uom_id`, `uom_po_id`,
`category_id`), 3 sales orders, and 10 linked order lines. Order lines carry
precomputed `price_subtotal` / `price_total` — reports aggregate those.

| File | Role |
|---|---|
| `app/Filament/Pages/Reports/SalesByCustomerReport.php` | Summary page: filters (form), `#[Computed]` aggregation, CSV export; customer cells link to the drill-down |
| `app/Filament/Pages/Reports/CustomerOrderLines.php` | Drill-down page: `#[Url]` query params (`partner`, `from`, `to`, `state`), KPIs, lines grouped by order; `$shouldRegisterNavigation = false` |
| `app/Filament/Pages/Reports/SalesByProductReport.php` | Sales by Product page: filters (incl. product/category selects), line-level aggregation, CSV export |
| `resources/views/filament/pages/reports/sales-by-product.blade.php` | Sales by Product view |
| `resources/views/filament/pages/reports/sales-by-customer.blade.php` | Summary view |
| `resources/views/filament/pages/reports/customer-order-lines.blade.php` | Drill-down view (KPI cards + per-order line tables) |
| `app/Providers/Filament/AdminPanelProvider.php` | `->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')` — required, app pages are NOT auto-discovered |

Gotchas learned:

- Attach to an existing cluster: `protected static ?string $cluster =
  Reporting::class;` (imports `Webkul\Accounting\Filament\Clusters\Reporting`)
  — no new nav group needed.
- Set an explicit `$slug` or Filament derives one from the class name
  (`SalesByCustomerReport` → `sales-by-customer-report`).
- Shield names page permissions from the **FQCN**
  (`page_filament_sales_by_customer_report`,
  `page_filament_customer_order_lines`), not the slug. After adding a
  page: run `shield:generate --all --option=permissions --panel=admin`, grant
  the new permission to roles, then `permission:cache-reset`.
- For drill-downs: use `#[Url]` Livewire attributes for state passed via query
  string, `protected static bool $shouldRegisterNavigation = false;` to keep
  the page out of the menu, and `Page::getUrl([...])` / `::getUrl([...])` to
  build links.

To add another report: copy the two files, change class/slug/view, point at
your data. Never edit `plugins/webkul/*` — the app-namespace approach survives
plugin updates.

---

## 8. Troubleshooting

| Symptom | Cause / Fix |
|---|---|
| `composer install` fails: *phpspreadsheet requires php <8.5* | Wrong PHP. Use `/opt/homebrew/opt/php@8.4/bin/php`. |
| `SQLSTATE[08006] could not connect to server` (pgsql) | PostgreSQL down: `brew services start postgresql@18`. |
| `password authentication failed for user "aureus"` | `.env` `DB_PASSWORD` must be `aureus_local_pg`. |
| `Allowed memory size exhausted` during plugin installs | Re-run with `-d memory_limit=1G`. |
| `Permission refresh failed:` during plugin install | Re-run `shield:generate --all --option=permissions --panel=admin` with 1G limit. |
| `aureuserp.test` unreachable / 404 | Run `valet status`; re-link if needed: `cd ~/Projects/aureuserp && valet link aureuserp` (in your terminal, sudo). |
| **Admin sidebar/top-nav missing module groups** (Manufacturing, Accounting, …) | New permissions generated by later plugin installs weren't attached to the Admin role. Fix: `/opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="\\Spatie\\Permission\\Models\\Role::where('name','Admin')->first()->syncPermissions(\\Spatie\\Permission\\Models\\Permission::all());"` then `artisan permission:cache-reset` and hard-refresh the browser. |
| Only Dashboard/Project/Recruitment/Website visible in top bar | By design (`topNavigation`): all other modules are in the **toggle button next to the logo**. Hard-refresh if the dropdown is empty (stale pre-fix page state). |
| `aureuserp.test` returns a Composer platform error | The site lost its PHP 8.4 isolation — re-run `valet isolate php@8.4 --site=aureuserp` (sudo, in your terminal). |
| Livewire actions fail, raw JSON / `Notice: ... Broken pipe` | Should be impossible under Valet (nginx+FPM). If it somehow reappears, check you're on `aureuserp.test`, not the old port-8000 URL. |
| Login page loads but login fails | Reset the password (below). |
| Blank page / missing styles | Rebuild assets: `npm install && npm run build`. |

**Reset the admin password:**

```bash
cd ~/Projects/aureuserp
/opt/homebrew/opt/php@8.4/bin/php artisan tinker --execute="
  \$u = \App\Models\User::where('email', 'admin@aureuserp.local')->first();
  \$u->password = bcrypt('NewPass@1234'); \$u->save();
  echo 'done';"
```

**Full database reset (⚠️ destroys all ERP data):**

```bash
cd ~/Projects/aureuserp
/opt/homebrew/opt/php@8.4/bin/php -d memory_limit=1G artisan erp:install --force \
  --admin-name="Admin" --admin-email="admin@aureuserp.local" \
  --admin-password="Admin@1234" --currency=USD
# then re-run the plugin loop from §3 Step 6
```

---

## 9. Uninstall / Cleanup

```bash
# 1. Stop services
brew services stop postgresql@18
valet unlink aureuserp

# 2. Drop the database (optional)
/opt/homebrew/opt/postgresql@18/bin/psql -d postgres \
  -c "DROP DATABASE IF EXISTS aureuserp; DROP USER IF EXISTS aureus;"

# 3. Remove the app (this deletes all ERP data)
rm -rf ~/Projects/aureuserp
```

Homebrew packages themselves are **not** removed by the steps above. To also
remove PostgreSQL (optional, affects other projects):
`brew uninstall postgresql@18`.

---

## 10. Reference Links

- Repository: https://github.com/aureuserp/aureuserp
- Developer docs: https://devdocs.aureuserp.com
- User guide: https://docs.aureuserp.com
- License: MIT (Webkul)
