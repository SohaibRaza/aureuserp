# Fast local test database

The normal feature-test bootstrap intentionally rebuilds and installs the ERP for a clean-room run. That is appropriate for CI, but it is too slow for repeated local focused tests.

The fresh bootstrap creates only the core ERP schema. Accounts owns its reconciliation migrations and runs them after its Products dependency and Accounts tables are available. The helper uses the same plugin installer as normal installations; it does not preload Accounts migrations to compensate for installation-order failures. Existing migration names remain unchanged. Table existence alone does not mean a plugin is installed: settings and permissions must also be initialized before the plugin is cached as ready.

Bootstrap failure tests must change installation markers through the helper's committed connection, then restore the marker, static cache and Artisan binding. A marker update inside the normal test transaction is invisible to the installer connection and can block that connection indefinitely.

For disposable PostgreSQL evidence, run `rtk proxy bash scripts/run_logistics_phase2a_tests.sh` followed by the explicit test file paths. Capture the final Pest test and assertion counts, any skipped cases and the PostgreSQL version. A wrapper exit code or the runner's initial isolation message alone does not establish that the suite completed.

Plugin installation visits each dependency once per top-level installation, including already installed dependencies so pending migrations are checked. Permission generation and application-cache refresh run once after that installation succeeds. A later top-level installation starts a new traversal; failures and dependency cycles do not retain completed traversal state. The permission-generation subprocess has a portable 60-second wall-clock timeout. Console panel discovery preserves the caller's PHP execution limit; the web panel retains its existing limit. These bounds do not impose an overall installation deadline.

Use the prepared-database mode only with the isolated `aureuserp_testing` database. It is opt-in and never falls back to the local application database.

## Private phase-runner databases

The phase runner creates and removes a fresh private database for each invocation and stops at the first test failure or setup error to avoid repeated installation after a bootstrap failure. PostgreSQL remains the default; pass `--engine=mysql` or `--engine mysql` to use a MySQL 8.0 server already available on `PATH`. The MySQL mode requires PHP 8.4 and `pdo_mysql`, initializes its own temporary data directory, disables TCP, MySQL X networking and binary logging, and uses a private Unix socket. It checks the connected server version, data directory, socket, database, user, `READ-COMMITTED` isolation and an explicit UTC application session before Pest starts. MySQL's default isolation is `REPEATABLE-READ`, so the runner starts its private server with `READ-COMMITTED` as the session default for the Accounts period-boundary contract.

The generated test user has no privileges on an application database. PostgreSQL grants `CREATEDB` inside its private cluster. MySQL limits DDL grants to database names matching `aureuserp_*_testing`, which permits isolated schema DDL tests to create and drop a second generated `_testing` database. The MySQL user also receives `PROCESS` and `SELECT` on `performance_schema` for lock-wait assertions. These grants exist only on the temporary server. The runner refuses MariaDB and MySQL versions outside 8.0; it does not install or download server binaries.

```bash
rtk proxy bash scripts/run_logistics_phase2a_tests.sh --engine=mysql \
  plugins/webkul/inventories/tests/Feature/Workflows/StockIdentityTest.php
```

Pass explicit repository test-file paths after the engine option. With no paths, the runner uses its default phase selection. `--engine=pgsql` is equivalent to the default.

## One-time preparation

This resets `aureuserp_testing`. Do not run it against another database.

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=aureuserp_testing \
  /opt/homebrew/opt/php@8.4/bin/php artisan migrate:fresh --force

APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=aureuserp_testing \
  /opt/homebrew/opt/php@8.4/bin/php artisan erp:install \
  --force \
  --admin-name='Test Admin' \
  --admin-email='admin@example.com' \
  --admin-password='admin123'
```

Apply any newly added migrations to the prepared test database before focused tests:

```bash
APP_ENV=testing DB_CONNECTION=pgsql DB_DATABASE=aureuserp_testing \
  /opt/homebrew/opt/php@8.4/bin/php artisan migrate --force
```

## Focused tests

```bash
AUREUSERP_TEST_REUSE_DATABASE=1 \
  /opt/homebrew/opt/php@8.4/bin/php \
  -d memory_limit=512M \
  vendor/bin/pest --compact tests/Feature/Recon/ReconciliationSchemaTest.php
```

The test suite still uses transactions for per-test isolation. Do not set `AUREUSERP_TEST_REUSE_DATABASE=1` for parallel workers (`TEST_TOKEN`); they retain the fresh worker-database path.

The runner resolves PHP from `PATH`; set `PHP_BIN` to an explicit PHP 8.4 executable when needed. The private MySQL server disables binary logging so its restricted test role can create the required triggers without SUPER. This is a test-server setting, not an application migration workaround. A MySQL deployment with binary logging enabled must provision the required trigger-creation privileges or an explicitly approved server policy before migrations; do not skip history triggers after a privilege error.
