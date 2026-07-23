#!/usr/bin/env bash
#
# The plugin version lives in three places that must agree, and they're
# edited by hand at release time. A mismatch is easy to make and ships
# silently, so fail the build on it.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

header=$(grep -m1 -E '^\s*\*\s*Version:' wellactually.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
constant=$(grep -m1 "define( 'WA_VERSION'" wellactually.php | sed -E "s/.*'WA_VERSION',[[:space:]]*'([^']+)'.*/\1/")
stable=$(grep -m1 -E '^Stable tag:' readme.txt | sed -E 's/Stable tag:[[:space:]]*//' | tr -d '[:space:]')

echo "  plugin header : ${header}"
echo "  WA_VERSION    : ${constant}"
echo "  readme.txt    : ${stable}"

if [ "$header" != "$constant" ] || [ "$header" != "$stable" ]; then
	echo "Version mismatch — these three must be identical." >&2
	exit 1
fi

if ! grep -q "^= ${header} =" readme.txt; then
	echo "readme.txt has no changelog entry for ${header}." >&2
	exit 1
fi

echo "Versions agree (${header}), and the changelog has an entry."
