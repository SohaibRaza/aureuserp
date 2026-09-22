#!/usr/bin/env bash

set -Eeuo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
TEMPORARY_ROOT="$(cd /tmp && pwd -P)"
TASK_ROOT="$(mktemp -d /tmp/aureus-p2a.XXXXXX)"
TASK_ROOT="$(cd "${TASK_ROOT}" && pwd -P)"
CLUSTER_ROOT="${TASK_ROOT}/postgres"
SOCKET_ROOT="${TASK_ROOT}/socket"
STORAGE_ROOT="${TASK_ROOT}/storage"
MARKER="${TASK_ROOT}/.aureuserp-phase2a-owned"
DATABASE="aureuserp_$(date -u +%Y%m%d%H%M%S)_$$_testing"
DATABASE_USER="aureuserp_test_runner"
DATABASE_PORT=5432
POSTGRES_LOG="${TASK_ROOT}/postgres.log"

DEFAULT_TESTS=(
    plugins/webkul/support/tests/Feature/Workflows/TestBootstrapPluginPathTest.php
    plugins/webkul/accounts/tests/Feature/Workflows/VendorBillTest.php
    plugins/webkul/accounts/tests/Feature/Workflows/PaymentRegisterBankAccountTest.php
    plugins/webkul/purchases/tests/Feature/Workflows/OrderBillingTest.php
    plugins/webkul/sales/tests/Feature/Workflows/OneStepSaleOrderTest.php
    plugins/webkul/sales/tests/Feature/API/V1/OrderTest.php
    plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationIntegrityTest.php
    plugins/webkul/sales/tests/Feature/Workflows/OrderConfirmationConcurrencyTest.php
    plugins/webkul/inventories/tests/Feature/Workflows/TransferIntegrityTest.php
    plugins/webkul/inventories/tests/Feature/Workflows/OneStepDeliveryTest.php
    plugins/webkul/inventories/tests/Feature/Workflows/ReplicationTest.php
    plugins/webkul/inventories/tests/Feature/API/V1/DeliveryTest.php
)

fail()
{
    echo "Phase 2A runner refused to continue: $*" >&2
    exit 1
}

owns_task_root()
{
    [[ -n "${TASK_ROOT:-}" ]]
    [[ "${TASK_ROOT}" == "${TEMPORARY_ROOT}"/aureus-p2a.* ]]
    [[ -f "${MARKER}" ]]
    [[ "$(<"${MARKER}")" == "${TASK_ROOT}" ]]
}

cleanup()
{
    local status=$?
    trap - EXIT INT TERM

    if ! owns_task_root; then
        echo "Cleanup refused because the task-owned marker or PostgreSQL identity is missing: ${TASK_ROOT}" >&2
        exit 70
    fi

    if (( status != 0 )) && [[ -s "${POSTGRES_LOG}" ]]; then
        echo "PostgreSQL diagnostic tail (synthetic disposable cluster):" >&2
        tail -n 40 "${POSTGRES_LOG}" >&2
    fi

    if [[ -f "${CLUSTER_ROOT}/PG_VERSION" ]] && pg_ctl -D "${CLUSTER_ROOT}" status >/dev/null 2>&1; then
        local actual_data_root
        actual_data_root="$(psql -XAt -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -d postgres -c "SHOW data_directory" 2>/dev/null || true)"

        if [[ "${actual_data_root}" != "${CLUSTER_ROOT}" ]]; then
            echo "Cleanup refused because the running PostgreSQL cluster identity changed: ${TASK_ROOT}" >&2
            exit 70
        fi

        pg_ctl -D "${CLUSTER_ROOT}" -m fast stop >/dev/null
    fi

    find "${TASK_ROOT}" -depth -delete
    exit "${status}"
}

chmod 0700 "${TASK_ROOT}"
printf '%s\n' "${TASK_ROOT}" > "${MARKER}"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for command in git php initdb pg_ctl createdb psql realpath; do
    command -v "${command}" >/dev/null || fail "required command not found: ${command}"
done

[[ "$(git -C "${REPOSITORY_ROOT}" rev-parse --show-toplevel)" == "${REPOSITORY_ROOT}" ]] \
    || fail "script is not running from the expected repository root"

if (( $# > 0 )); then
    TESTS=("$@")
else
    TESTS=("${DEFAULT_TESTS[@]}")
fi

RESOLVED_TESTS=()

for test_path in "${TESTS[@]}"; do
    [[ "${test_path}" != -* ]] || fail "options are not accepted; pass explicit test file paths only"
    [[ "${test_path}" != /* ]] || fail "absolute test paths are not accepted: ${test_path}"
    [[ "/${test_path}/" != *"/../"* ]] || fail "parent traversal is not accepted: ${test_path}"
    [[ -f "${REPOSITORY_ROOT}/${test_path}" ]] || fail "test path does not exist: ${test_path}"

    resolved_test_path="$(realpath "${REPOSITORY_ROOT}/${test_path}")"
    [[ "${resolved_test_path}" == "${REPOSITORY_ROOT}"/* ]] \
        || fail "test path must remain inside the repository: ${test_path}"

    repository_test_path="${resolved_test_path#"${REPOSITORY_ROOT}/"}"
    [[ "${repository_test_path}" == tests/*.php || "${repository_test_path}" == */tests/*.php ]] \
        || fail "path is not a repository test file: ${test_path}"

    RESOLVED_TESTS+=("${resolved_test_path}")
done

TESTS=("${RESOLVED_TESTS[@]}")

mkdir -m 0700 "${SOCKET_ROOT}" "${STORAGE_ROOT}"
mkdir -p "${STORAGE_ROOT}/framework/cache" "${STORAGE_ROOT}/framework/sessions" \
    "${STORAGE_ROOT}/framework/testing/views" "${STORAGE_ROOT}/logs" \
    "${STORAGE_ROOT}/app/private" "${STORAGE_ROOT}/app/public"

initdb -D "${CLUSTER_ROOT}" -U postgres --auth-local=trust --auth-host=reject --no-locale --encoding=UTF8 >/dev/null
pg_ctl -D "${CLUSTER_ROOT}" -l "${POSTGRES_LOG}" \
    -o "-k ${SOCKET_ROOT} -p ${DATABASE_PORT} -c listen_addresses=''" start >/dev/null

psql -Xv ON_ERROR_STOP=1 -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -d postgres \
    -c "CREATE ROLE ${DATABASE_USER} LOGIN" >/dev/null
createdb -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -O "${DATABASE_USER}" "${DATABASE}"

SERVER_IDENTITY="$(psql -XAt -F '|' -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -d postgres \
    -c "SELECT current_setting('data_directory'), inet_server_addr() IS NULL")"
CLIENT_IDENTITY="$(psql -XAt -F '|' -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U "${DATABASE_USER}" -d "${DATABASE}" \
    -c "SELECT current_database(), current_user")"

[[ "${SERVER_IDENTITY}" == "${CLUSTER_ROOT}|t" && "${CLIENT_IDENTITY}" == "${DATABASE}|${DATABASE_USER}" ]] \
    || fail "private PostgreSQL identity did not match the task-owned resource"
[[ "${DATABASE}" == aureuserp_*_testing ]] \
    || fail "generated database name does not satisfy the _testing guard"

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY='base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='
export APP_URL='http://localhost'
export DB_CONNECTION=pgsql
export DB_HOST="${SOCKET_ROOT}"
export DB_PORT="${DATABASE_PORT}"
export DB_DATABASE="${DATABASE}"
export DB_USERNAME="${DATABASE_USER}"
export DB_PASSWORD=''
export LARAVEL_STORAGE_PATH="${STORAGE_ROOT}"
export VIEW_COMPILED_PATH="${STORAGE_ROOT}/framework/testing/views"
export APP_CONFIG_CACHE="${TASK_ROOT}/cache/config.php"
export APP_EVENTS_CACHE="${TASK_ROOT}/cache/events.php"
export APP_PACKAGES_CACHE="${TASK_ROOT}/cache/packages.php"
export APP_ROUTES_CACHE="${TASK_ROOT}/cache/routes.php"
export APP_SERVICES_CACHE="${TASK_ROOT}/cache/services.php"
export CACHE_STORE=array
export SESSION_DRIVER=array
export MAIL_MAILER=array
export QUEUE_CONNECTION=sync
export FILESYSTEM_DISK=local
export BROADCAST_CONNECTION=null
export LOG_CHANNEL=null
export MSG91_AUTH_KEY=''
export MSG91_ADMIN_MOBILE=''
export AUREUSERP_RUN_SALES_CONFIRMATION_CONCURRENCY=1

mkdir -p "${TASK_ROOT}/cache"

cd "${REPOSITORY_ROOT}"
APPLICATION_IDENTITY="$(php -d memory_limit=512M -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$identity = $connection->selectOne("SELECT current_database() AS database, current_user AS username, inet_server_addr() IS NULL AS local_socket");
echo implode("|", [
    $app->environment(),
    $connection->getDriverName(),
    $identity->database,
    $identity->username,
    $identity->local_socket ? "socket" : "tcp",
    config("database.connections.pgsql.host"),
    storage_path(),
    config("cache.default"),
    config("session.driver"),
    config("mail.default"),
    config("queue.default"),
    config("filesystems.default"),
]);
')"
EXPECTED_APPLICATION_IDENTITY="testing|pgsql|${DATABASE}|${DATABASE_USER}|socket|${SOCKET_ROOT}|${STORAGE_ROOT}|array|array|array|sync|local"

[[ "${APPLICATION_IDENTITY}" == "${EXPECTED_APPLICATION_IDENTITY}" ]] \
    || fail "Laravel effective test configuration did not match the task-owned resources"

echo "Isolation verified: PostgreSQL $(psql --version | awk '{print $3}'), private task-owned cluster, database ${DATABASE}."
echo "Running ${#TESTS[@]} selected test files with PHP $(php -r 'echo PHP_VERSION;') and memory_limit=512M."

php -d memory_limit=512M -d max_execution_time=0 vendor/bin/pest --compact "${TESTS[@]}"
