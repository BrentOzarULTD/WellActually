#!/usr/bin/env bash
#
# Fetch the WordPress test library and create the test database.
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}

if [ "$WP_VERSION" = "latest" ]; then
	# The version-check API lists an offer per supported branch, newest first.
	# Take the first one; a greedy match here silently picks the oldest branch,
	# which is how CI ended up testing against WordPress 4.7.
	WP_VERSION=$(curl -fsS https://api.wordpress.org/core/version-check/1.7/ \
		| grep -o '"version":"[0-9.]*"' | head -1 | cut -d'"' -f4)
fi

if [ -z "$WP_VERSION" ]; then
	echo "Could not work out which WordPress version to install." >&2
	exit 1
fi

# wordpress-develop tags are always three-part: 6.5 is tagged 6.5.0.
case "$WP_VERSION" in
	*.*.*) WP_TAG="$WP_VERSION" ;;
	*.*)   WP_TAG="${WP_VERSION}.0" ;;
	*)     WP_TAG="${WP_VERSION}.0.0" ;;
esac

echo "Installing the WordPress ${WP_TAG} test library into ${WP_TESTS_DIR}"

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
	mkdir -p "$WP_TESTS_DIR"
	tmp=$(mktemp -d)
	# -f so a 404 fails here rather than as a confusing tar error.
	curl -fsSL "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WP_TAG}.tar.gz" -o "$tmp/wp.tar.gz"
	tar xzf "$tmp/wp.tar.gz" -C "$tmp"
	cp -R "$tmp/wordpress-develop-${WP_TAG}/tests/phpunit/includes" "$WP_TESTS_DIR/"
	cp -R "$tmp/wordpress-develop-${WP_TAG}/tests/phpunit/data" "$WP_TESTS_DIR/" 2>/dev/null || true
	# The test library boots WordPress itself, so it needs core too.
	cp -R "$tmp/wordpress-develop-${WP_TAG}/src" "$WP_TESTS_DIR/wordpress"
	rm -rf "$tmp"
fi

cat > "$WP_TESTS_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '${WP_TESTS_DIR}/wordpress/' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_DEBUG', true );
PHP

mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null || true

echo "Done."
