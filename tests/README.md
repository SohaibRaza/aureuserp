# Fast local test database

The normal feature-test bootstrap intentionally rebuilds and installs the ERP for a clean-room run. That is appropriate for CI, but it is too slow for repeated local focused tests.

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
