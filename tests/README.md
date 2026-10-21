# Fast local test database

The normal feature-test bootstrap intentionally rebuilds and installs the ERP for a clean-room run. That is appropriate for CI, but it is too slow for repeated local focused tests.

The fresh bootstrap creates Products and Accounts tables before other plugin installations. Table existence alone does not mean a plugin is installed: the helper runs the plugin installer when its installation record is missing, so settings and permissions are initialized before the plugin is cached as ready. This keeps combined runs independent of whether Inventory or Accounts tests initialize the database first.

Bootstrap failure tests must change installation markers through the helper's committed connection, then restore the marker, static cache and Artisan binding. A marker update inside the normal test transaction is invisible to the installer connection and can block that connection indefinitely.

For disposable PostgreSQL evidence, run `rtk proxy bash scripts/run_logistics_phase2a_tests.sh` followed by the explicit test file paths. Capture the final Pest test and assertion counts, any skipped cases and the PostgreSQL version. A wrapper exit code or the runner's initial isolation message alone does not establish that the suite completed.

Plugin installation visits each dependency once per top-level installation, including already installed dependencies so pending migrations are checked. Permission generation and application-cache refresh run once after that installation succeeds. A later top-level installation starts a new traversal; failures and dependency cycles do not retain completed traversal state. The permission-generation subprocess has a portable 60-second wall-clock timeout. Console panel discovery preserves the caller's PHP execution limit; the web panel retains its existing limit. These bounds do not impose an overall installation deadline.

Use the prepared-database mode only with the isolated `aureuserp_testing` database. It is opt-in and never falls back to the local application database.

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
