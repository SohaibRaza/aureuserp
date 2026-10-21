#!/usr/bin/env bash

set -Eeuo pipefail

REPOSITORY_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
TEMPORARY_ROOT="$(cd /tmp && pwd -P)"
ENGINE=pgsql
ARGS=()
while (( $# > 0 )); do
    case "$1" in
        --engine=pgsql|--engine=postgres|--engine=postgresql) ENGINE=pgsql; shift ;;
        --engine=mysql) ENGINE=mysql; shift ;;
        --engine)
            (( $# >= 2 )) || { echo "--engine requires pgsql or mysql" >&2; exit 1; }
            case "$2" in
                pgsql|postgres|postgresql) ENGINE=pgsql ;;
                mysql) ENGINE=mysql ;;
                *) echo "Unsupported engine: $2" >&2; exit 1 ;;
            esac
            shift 2
            ;;
        *) ARGS+=("$1"); shift ;;
    esac
done
set -- "${ARGS[@]}"
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
MYSQL_DATA_ROOT="${TASK_ROOT}/mysql"
MYSQL_LOG="${TASK_ROOT}/mysql.log"
MYSQL_PID_FILE="${TASK_ROOT}/mysql.pid"
MYSQL_SOCKET="${SOCKET_ROOT}/mysql.sock"
MYSQL_PASSWORD='aureus-test-only'
MYSQL_SERVER_PROCESS_PID=''
PHP_BIN="${PHP_BIN:-php}"

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
    [[ "$(<"${MARKER}")" == "${TASK_ROOT}|${ENGINE}" ]]
}

cleanup()
{
    local status=$?
    trap - EXIT INT TERM

    if ! owns_task_root; then
        echo "Cleanup refused because the task-owned marker or resource identity is missing: ${TASK_ROOT}" >&2
        exit 70
    fi

    if (( status != 0 )) && [[ "${ENGINE}" == pgsql && -s "${POSTGRES_LOG}" ]]; then
        echo "PostgreSQL diagnostic tail (synthetic disposable cluster):" >&2
        tail -n 40 "${POSTGRES_LOG}" >&2
    fi

    if (( status != 0 )) && [[ "${ENGINE}" == mysql && -s "${MYSQL_LOG}" ]]; then
        echo "MySQL diagnostic tail (synthetic disposable server):" >&2
        tail -n 100 "${MYSQL_LOG}" >&2
    fi

    if [[ "${ENGINE}" == pgsql && -f "${CLUSTER_ROOT}/PG_VERSION" ]] && pg_ctl -D "${CLUSTER_ROOT}" status >/dev/null 2>&1; then
        local actual_data_root
        actual_data_root="$(psql -XAt -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -d postgres -c "SHOW data_directory" 2>/dev/null || true)"

        if [[ "${actual_data_root}" != "${CLUSTER_ROOT}" ]]; then
            echo "Cleanup refused because the running PostgreSQL cluster identity changed: ${TASK_ROOT}" >&2
            exit 70
        fi

        pg_ctl -D "${CLUSTER_ROOT}" -m fast stop >/dev/null
    fi

    if [[ "${ENGINE}" == mysql ]]; then
        local mysql_pid mysql_identity mysql_data_root mysql_socket mysql_skip_networking mysql_version
        mysql_pid="${MYSQL_SERVER_PROCESS_PID}"
        if [[ -z "${mysql_pid}" && -f "${MYSQL_PID_FILE}" ]]; then
            mysql_pid="$(<"${MYSQL_PID_FILE}")"
        fi
        if [[ "${mysql_pid}" =~ ^[0-9]+$ ]] && kill -0 "${mysql_pid}" 2>/dev/null; then
            mysql_identity="$(mysql --no-defaults --protocol=SOCKET --socket="${MYSQL_SOCKET}" -uroot -Nse \
                "SELECT CONCAT_WS('|', @@datadir, @@socket, @@skip_networking, VERSION())" 2>/dev/null || true)"
            IFS='|' read -r mysql_data_root mysql_socket mysql_skip_networking mysql_version <<< "${mysql_identity}"
            [[ "${mysql_data_root}" == "${MYSQL_DATA_ROOT}/" && "${mysql_socket}" == "${MYSQL_SOCKET}" \
                && "${mysql_skip_networking}" == 1 && "${mysql_version}" == 8.0.* ]] || {
                echo "Cleanup refused because the running MySQL identity or version changed: ${TASK_ROOT}" >&2
                exit 70
            }
            mysqladmin --no-defaults --protocol=SOCKET --socket="${MYSQL_SOCKET}" -uroot shutdown >/dev/null
        fi
    fi

    find "${TASK_ROOT}" -depth -delete
    exit "${status}"
}

chmod 0700 "${TASK_ROOT}"
printf '%s\n' "${TASK_ROOT}|${ENGINE}" > "${MARKER}"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for command in git realpath; do
    command -v "${command}" >/dev/null || fail "required command not found: ${command}"
done
PHP_BIN="$(command -v "${PHP_BIN}")" || fail "PHP 8.4 executable not found"
[[ -x "${PHP_BIN}" ]] || fail "PHP 8.4 executable not found: ${PHP_BIN}"
[[ "$("${PHP_BIN}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" == 8.4 ]] || fail "runner requires PHP 8.4"

if [[ "${ENGINE}" == pgsql ]]; then
    for command in initdb pg_ctl createdb psql; do
        command -v "${command}" >/dev/null || fail "required command not found: ${command}"
    done
else
    for command in mysqld mysql mysqladmin; do
        command -v "${command}" >/dev/null || fail "required command not found: ${command}"
    done
    MYSQL_SERVER_VERSION="$(mysqld --no-defaults --version 2>&1)"
    [[ "${MYSQL_SERVER_VERSION}" == *"Ver 8.0."* && "${MYSQL_SERVER_VERSION}" != *MariaDB* ]] \
        || fail "--engine=mysql requires MySQL 8.0; found ${MYSQL_SERVER_VERSION}"
fi

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

if [[ "${ENGINE}" == pgsql ]]; then
    initdb -D "${CLUSTER_ROOT}" -U postgres --auth-local=trust --auth-host=reject --no-locale --encoding=UTF8 >/dev/null
    pg_ctl -D "${CLUSTER_ROOT}" -l "${POSTGRES_LOG}" \
        -o "-k ${SOCKET_ROOT} -p ${DATABASE_PORT} -c listen_addresses=''" start >/dev/null

    psql -Xv ON_ERROR_STOP=1 -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -d postgres \
        -c "CREATE ROLE ${DATABASE_USER} LOGIN CREATEDB" >/dev/null
    createdb -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -O "${DATABASE_USER}" "${DATABASE}"

    SERVER_IDENTITY="$(psql -XAt -F '|' -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U postgres -d postgres \
        -c "SELECT current_setting('data_directory'), inet_server_addr() IS NULL")"
    CLIENT_IDENTITY="$(psql -XAt -F '|' -h "${SOCKET_ROOT}" -p "${DATABASE_PORT}" -U "${DATABASE_USER}" -d "${DATABASE}" \
        -c "SELECT current_database(), current_user")"

    [[ "${SERVER_IDENTITY}" == "${CLUSTER_ROOT}|t" && "${CLIENT_IDENTITY}" == "${DATABASE}|${DATABASE_USER}" ]] \
        || fail "private PostgreSQL identity did not match the task-owned resource"
else
    mkdir -m 0700 "${MYSQL_DATA_ROOT}"
    MYSQL_SERVER_BIN="$(realpath "$(command -v mysqld)")"
    MYSQL_BASEDIR="$(cd "$(dirname "${MYSQL_SERVER_BIN}")/.." && pwd -P)"
    mysqld --no-defaults --initialize-insecure --basedir="${MYSQL_BASEDIR}" --datadir="${MYSQL_DATA_ROOT}" --user="$(id -un)" >/dev/null 2>&1
    mysqld --no-defaults --basedir="${MYSQL_BASEDIR}" --datadir="${MYSQL_DATA_ROOT}" --user="$(id -un)" \
        --socket="${MYSQL_SOCKET}" --pid-file="${MYSQL_PID_FILE}" --log-error="${MYSQL_LOG}" \
        --skip-networking --skip-log-bin --mysqlx=0 --transaction-isolation=READ-COMMITTED &
    MYSQL_SERVER_PROCESS_PID=$!
    for _ in {1..60}; do
        if mysql --no-defaults --protocol=SOCKET --socket="${MYSQL_SOCKET}" -uroot -Nse 'SELECT 1' >/dev/null 2>&1; then
            break
        fi
        sleep 1
    done

    MYSQL_SERVER_IDENTITY="$(mysql --no-defaults --protocol=SOCKET --socket="${MYSQL_SOCKET}" -uroot -Nse \
        "SELECT CONCAT_WS('|', @@datadir, @@socket, @@skip_networking, VERSION(), @@GLOBAL.transaction_isolation)")"
    IFS='|' read -r actual_data_root actual_socket skip_networking mysql_version default_isolation <<< "${MYSQL_SERVER_IDENTITY}"
    [[ "${actual_data_root}" == "${MYSQL_DATA_ROOT}/" && "${actual_socket}" == "${MYSQL_SOCKET}" \
        && "${skip_networking}" == 1 && "${mysql_version}" == 8.0.* && "${default_isolation}" == READ-COMMITTED ]] \
        || fail "private MySQL identity/version/isolation check failed: ${MYSQL_SERVER_IDENTITY}"
    mysql --no-defaults --protocol=SOCKET --socket="${MYSQL_SOCKET}" -uroot <<SQL
CREATE DATABASE \`${DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER '${DATABASE_USER}'@'localhost' IDENTIFIED BY '${MYSQL_PASSWORD}';
GRANT ALL PRIVILEGES ON \`aureuserp\_%\_testing\`.* TO '${DATABASE_USER}'@'localhost';
GRANT PROCESS ON *.* TO '${DATABASE_USER}'@'localhost';
GRANT SELECT ON performance_schema.* TO '${DATABASE_USER}'@'localhost';
SQL
    CLIENT_IDENTITY="$(mysql --no-defaults --protocol=SOCKET --socket="${MYSQL_SOCKET}" \
        -u"${DATABASE_USER}" -p"${MYSQL_PASSWORD}" "${DATABASE}" -Nse "SELECT CONCAT_WS('|', DATABASE(), CURRENT_USER())")"
    [[ "${CLIENT_IDENTITY}" == "${DATABASE}|${DATABASE_USER}@localhost" ]] \
        || fail "private MySQL client identity did not match the task-owned resource"
fi
[[ "${DATABASE}" == aureuserp_*_testing ]] \
    || fail "generated database name does not satisfy the _testing guard"

export APP_ENV=testing
export APP_DEBUG=false
export APP_KEY='base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE='
export APP_URL='http://localhost'
export DB_CONNECTION="${ENGINE}"
export AUREUSERP_TEST_REUSE_DATABASE=0
export TEST_TOKEN=
export DB_URL=
export DB_SOCKET=
export DB_HOST="${SOCKET_ROOT}"
export DB_PORT="${DATABASE_PORT}"
export DB_DATABASE="${DATABASE}"
export DB_USERNAME="${DATABASE_USER}"
export DB_PASSWORD=''
if [[ "${ENGINE}" == mysql ]]; then
    export DB_SOCKET="${MYSQL_SOCKET}"
    export DB_HOST=localhost
    export DB_PORT=3306
    export DB_PASSWORD="${MYSQL_PASSWORD}"
fi
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
export AUREUSERP_RUN_CONCURRENCY_TEST=1

mkdir -p "${TASK_ROOT}/cache"

cd "${REPOSITORY_ROOT}"
if ! APPLICATION_IDENTITY="$("${PHP_BIN}" -d memory_limit=512M -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
$identity = $connection->getDriverName() === "pgsql"
    ? $connection->selectOne("SELECT current_database() AS db_name, current_user AS db_user, inet_server_addr() IS NULL AS socket_only")
    : $connection->selectOne("SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user, @@socket AS socket_path, @@skip_networking AS socket_only, @@session.transaction_isolation AS isolation_level, @@session.time_zone AS session_timezone");
$socketOnly = $identity->socket_only;
$host = $connection->getDriverName() === "pgsql" ? config("database.connections.pgsql.host") : $identity->socket_path;
echo implode("|", [
    $app->environment(),
    $connection->getDriverName(),
    $identity->db_name,
    $identity->db_user,
    $socketOnly ? "socket" : "tcp",
    $host,
    storage_path(),
    config("cache.default"),
    config("session.driver"),
    config("mail.default"),
    config("queue.default"),
    config("filesystems.default"),
    ...($connection->getDriverName() === "mysql" ? [$identity->isolation_level, $identity->session_timezone] : []),
]);
')"; then
    fail "Laravel test-configuration probe failed: ${APPLICATION_IDENTITY}"
fi
if [[ "${ENGINE}" == pgsql ]]; then
    EXPECTED_APPLICATION_IDENTITY="testing|pgsql|${DATABASE}|${DATABASE_USER}|socket|${SOCKET_ROOT}|${STORAGE_ROOT}|array|array|array|sync|local"
else
    EXPECTED_APPLICATION_IDENTITY="testing|mysql|${DATABASE}|${DATABASE_USER}@localhost|socket|${MYSQL_SOCKET}|${STORAGE_ROOT}|array|array|array|sync|local|READ-COMMITTED|+00:00"
fi

[[ "${APPLICATION_IDENTITY}" == "${EXPECTED_APPLICATION_IDENTITY}" ]] \
    || fail "Laravel effective test configuration did not match the task-owned resources"

if [[ "${ENGINE}" == pgsql ]]; then
    echo "Isolation verified: PostgreSQL $(psql --version | awk '{print $3}'), private task-owned cluster, database ${DATABASE}."
else
    echo "Isolation verified: MySQL ${mysql_version}, private task-owned socket-only server, database ${DATABASE}, READ-COMMITTED server default and UTC application sessions."
fi
echo "Running ${#TESTS[@]} selected test files with PHP $("${PHP_BIN}" -r 'echo PHP_VERSION;') and memory_limit=512M."

"${PHP_BIN}" -d memory_limit=512M -d max_execution_time=0 vendor/bin/pest --compact --stop-on-defect "${TESTS[@]}"
