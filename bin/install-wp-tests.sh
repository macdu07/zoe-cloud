#!/usr/bin/env bash
set -euo pipefail

WP_VERSION="${WP_VERSION:-latest}"
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"

if [[ ! -f "${WP_TESTS_DIR}/includes/functions.php" ]]; then
	mkdir -p "${WP_TESTS_DIR}" "${WP_CORE_DIR}"

if [[ "${WP_VERSION}" == "latest" ]]; then
	ARCHIVE_URL="https://wordpress.org/latest.tar.gz"
	DEVELOP_URL="https://github.com/WordPress/wordpress-develop/archive/refs/heads/trunk.tar.gz"
else
	ARCHIVE_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"
	DEVELOP_URL="https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_VERSION}.tar.gz"
fi

	curl --fail --silent --show-error --location "${ARCHIVE_URL}" | tar --strip-components=1 -xz -C "${WP_CORE_DIR}"
	DEVELOP_DIR="$(mktemp -d)"
	trap 'rm -rf "${DEVELOP_DIR}"' EXIT
	curl --fail --silent --show-error --location "${DEVELOP_URL}" | tar --strip-components=1 -xz -C "${DEVELOP_DIR}"
	cp -R "${DEVELOP_DIR}/tests/phpunit/includes" "${WP_TESTS_DIR}/includes"
	cp -R "${DEVELOP_DIR}/tests/phpunit/data" "${WP_TESTS_DIR}/data"
	sed "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}/':" "${DEVELOP_DIR}/wp-tests-config-sample.php" \
		| sed "s/youremptytestdbnamehere/${WP_TESTS_DB_NAME:-wordpress_test}/" \
		| sed "s/yourusernamehere/${WP_TESTS_DB_USER:-root}/" \
		| sed "s/yourpasswordhere/${WP_TESTS_DB_PASSWORD:-root}/" \
		| sed "s|localhost|${WP_TESTS_DB_HOST:-db}|" > "${WP_TESTS_DIR}/wp-tests-config.php"
fi

if command -v mysqladmin >/dev/null 2>&1; then
	mysqladmin --host="${WP_TESTS_DB_HOST:-db}" --user="${WP_TESTS_DB_USER:-root}" --password="${WP_TESTS_DB_PASSWORD:-root}" create "${WP_TESTS_DB_NAME:-wordpress_test}" 2>/dev/null || true
fi
