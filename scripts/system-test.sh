#!/usr/bin/env bash
set -euo pipefail

# Wiring runs on PHP 8.2: indexed psr/cache requires >=8.0 and cache/integration-tests dev-master
# requires ^8.2. The library's root "php": "~7.1 || ^8.0" constraint is unchanged.

USE_SUDO_DOCKER="${USE_SUDO_DOCKER:-0}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
IMAGE_TAG="cache-2-system-test:remote-test-2026-11-09"
COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
OUT_DIR="${REPO_ROOT}/.system-test"
UPDATE_LOG="${OUT_DIR}/composer-update.log"
AUDIT_LOG="${OUT_DIR}/composer-audit.log"
PIN_LOG="${OUT_DIR}/pin-check.log"
PHPUNIT_LOG="${REPO_ROOT}/.phpunit-system-test.log"
JUNIT_FILE="${REPO_ROOT}/.phpunit-system-test.junit.xml"

mkdir -p "${OUT_DIR}"

docker_cmd() {
    if [[ "${USE_SUDO_DOCKER}" == "1" ]]; then
        sudo docker "$@"
    else
        docker "$@"
    fi
}

strip_ansi() {
    sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g'
}

reject_diagnostics() {
    local label="$1"
    local file="$2"
    if grep -E '^(PHP )?(Warning|Deprecated|Notice):' "${file}" >/dev/null; then
        echo "${label}: output contains Warning, Deprecated, or Notice" >&2
        grep -E '^(PHP )?(Warning|Deprecated|Notice):' "${file}" >&2 || true
        exit 1
    fi
}

echo "Building ${IMAGE_TAG}..."
docker_cmd build -f "${REPO_ROOT}/docker/system-test/Dockerfile" -t "${IMAGE_TAG}" "${REPO_ROOT}"

set +e
docker_cmd run --rm \
    -v "${REPO_ROOT}:/app" \
    -w /app \
    "${IMAGE_TAG}" \
    bash -lc "
        set -euo pipefail
        export COMPOSER_ALLOW_SUPERUSER=1
        git config --global --add safe.directory /app
        mkdir -p /app/.system-test

        curl -fsSL -o /tmp/composer.phar '${COMPOSER_URL}'
        echo '${COMPOSER_SHA256}  /tmp/composer.phar' | sha256sum -c -

        php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress \
            > /app/.system-test/composer-update.log 2>&1
        cat /app/.system-test/composer-update.log

        php /tmp/composer.phar audit > /app/.system-test/composer-audit.log 2>&1
        cat /app/.system-test/composer-audit.log

        php scripts/check-cluster-pins.php > /app/.system-test/pin-check.log 2>&1
        cat /app/.system-test/pin-check.log

        umask 0002
        vendor/bin/phpunit --colors=never --do-not-cache-result --no-coverage \
            --log-junit /app/.phpunit-system-test.junit.xml \
            > /app/.phpunit-system-test.log 2>&1
        cat /app/.phpunit-system-test.log

        php scripts/check-phpunit-gate.php \
            /app/.phpunit-system-test.log \
            /app/.phpunit-system-test.junit.xml
    "
RUN_STATUS=$?
set -e

if [[ ${RUN_STATUS} -ne 0 ]]; then
    echo "Container test run exited with status ${RUN_STATUS}." >&2
    exit "${RUN_STATUS}"
fi

UPDATE_STRIPPED="$(mktemp)"
AUDIT_STRIPPED="$(mktemp)"
PIN_STRIPPED="$(mktemp)"
PHPUNIT_STRIPPED="$(mktemp)"
strip_ansi < "${UPDATE_LOG}" > "${UPDATE_STRIPPED}"
strip_ansi < "${AUDIT_LOG}" > "${AUDIT_STRIPPED}"
strip_ansi < "${PIN_LOG}" > "${PIN_STRIPPED}"
strip_ansi < "${PHPUNIT_LOG}" > "${PHPUNIT_STRIPPED}"

reject_diagnostics "composer update" "${UPDATE_STRIPPED}"
reject_diagnostics "composer audit" "${AUDIT_STRIPPED}"
reject_diagnostics "pin-check" "${PIN_STRIPPED}"
reject_diagnostics "phpunit" "${PHPUNIT_STRIPPED}"

if ! grep -Fxq 'No security vulnerability advisories found.' "${AUDIT_STRIPPED}"; then
    echo 'composer audit: missing exact success line' >&2
    exit 1
fi

if ! grep -Fxq 'check-cluster-pins: wrong-SHA demo failed as expected for installed.php reference' "${PIN_STRIPPED}"; then
    echo 'pin-check: missing installed.php wrong-SHA demo success line' >&2
    exit 1
fi

if ! grep -Fxq 'check-cluster-pins: wrong-SHA demo failed as expected for installed.json source.reference' "${PIN_STRIPPED}"; then
    echo 'pin-check: missing installed.json wrong-SHA demo success line' >&2
    exit 1
fi

if ! grep -Fxq 'check-cluster-pins: all cluster packages match expected SHAs and aliases.' "${PIN_STRIPPED}"; then
    echo 'pin-check: missing final success line' >&2
    exit 1
fi

if ! grep -Eq '^OK, but some tests were skipped!$' "${PHPUNIT_STRIPPED}" \
    && ! grep -Eq '^OK \([0-9]+ tests?, [0-9]+ assertions?\)$' "${PHPUNIT_STRIPPED}"; then
    echo 'phpunit: missing strict OK summary line' >&2
    exit 1
fi

if ! grep -Eq '^Tests: [0-9]+, Assertions: [0-9]+(, Skipped: [0-9]+)?\.$' "${PHPUNIT_STRIPPED}"; then
    echo 'phpunit: missing Tests: summary line' >&2
    exit 1
fi

docker_cmd run --rm \
    -v "${REPO_ROOT}:/app" \
    -w /app \
    "${IMAGE_TAG}" \
    php scripts/check-phpunit-gate.php \
        /app/.phpunit-system-test.log \
        /app/.phpunit-system-test.junit.xml

rm -f "${UPDATE_STRIPPED}" "${AUDIT_STRIPPED}" "${PIN_STRIPPED}" "${PHPUNIT_STRIPPED}"

echo "system-test: all steps passed."
