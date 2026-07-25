#!/usr/bin/env bash
#
# The plugin version lives in three places that must agree, and they're
# edited by hand at release time. A mismatch is easy to make and ships
# silently, so fail the build on it.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

header=$(grep -m1 -E '^\s*\*\s*Version:' wellactually.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
constant=$(grep -m1 "define( 'WELLACTUALLY_VERSION'" wellactually.php | sed -E "s/.*'WELLACTUALLY_VERSION',[[:space:]]*'([^']+)'.*/\1/")
stable=$(grep -m1 -E '^Stable tag:' readme.txt | sed -E 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')
text_domain=$(grep -m1 -E '^\s*\*\s*Text Domain:' wellactually.php | sed -E 's/.*Text Domain:[[:space:]]*//' | tr -d '[:space:]')
expected_text_domain="well-actually"

echo "  plugin header : ${header}"
echo "  WELLACTUALLY_VERSION    : ${constant}"
echo "  readme.txt    : ${stable}"
echo "  text domain   : ${text_domain}"

if [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Version mismatch — these three must be identical." >&2
	exit 1
fi

if ! grep -q "^= ${header} =" readme.txt; then
	echo "readme.txt has no changelog entry for ${header}." >&2
	exit 1
fi

if [ "$text_domain" != "$expected_text_domain" ]; then
	echo "Text domain must match the WordPress.org slug: ${expected_text_domain}." >&2
	exit 1
fi

if [ ! -f "languages/${expected_text_domain}.pot" ]; then
	echo "Missing languages/${expected_text_domain}.pot translation catalog." >&2
	exit 1
fi

echo "Versions agree (${header}), the changelog has an entry, and the text domain matches WordPress.org."
