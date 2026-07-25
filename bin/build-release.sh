#!/usr/bin/env bash
#
# Build a distributable WellActually plugin zip.
#
# Copies only the files a WordPress site needs, into a top-level
# `well-actually/` folder (matching the assigned WordPress.org slug), so
# unzipping drops straight into wp-content/plugins/well-actually, and zips it
# as dist/well-actually-<version>.zip.
#
# Usage:
#   bin/build-release.sh
#
# Output:
#   dist/well-actually-<version>.zip

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

MAIN_FILE="wellactually.php"
PLUGIN_SLUG="well-actually"

if [ ! -f "$MAIN_FILE" ]; then
	echo "error: $MAIN_FILE not found — run this from the plugin repo." >&2
	exit 1
fi

VERSION=$(grep -m1 "^ \* Version:" "$MAIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
	echo "error: could not parse Version from $MAIN_FILE" >&2
	exit 1
fi

BUILD_DIR="build/$PLUGIN_SLUG"
DIST_DIR="dist"
ZIP_PATH="$DIST_DIR/$PLUGIN_SLUG-$VERSION.zip"

echo "Building WellActually v$VERSION..."

rm -rf "build" "$ZIP_PATH"
mkdir -p "$BUILD_DIR" "$DIST_DIR"

# Only what a live WordPress site needs to run the plugin. Everything else
# (dev tooling, tests, the local test harness, docs for contributors) stays
# out of the shipped zip.
SHIP_PATHS=(
	"$MAIN_FILE"
	"uninstall.php"
	"readme.txt"
	"LICENSE"
	"includes"
	"templates"
	"assets"
	"languages"
)

for path in "${SHIP_PATHS[@]}"; do
	if [ ! -e "$path" ]; then
		echo "error: expected shipping path '$path' not found" >&2
		exit 1
	fi
	cp -R "$path" "$BUILD_DIR/$path"
done

# Guard against anything dev-only sneaking in via a shipped directory
# (e.g. an editor swap file, a stray .DS_Store).
find "$BUILD_DIR" \( -name ".DS_Store" -o -name "*.orig" -o -name "*.bak" \) -delete

echo "Files staged in $BUILD_DIR:"
find "$BUILD_DIR" -type f | sed "s#^$BUILD_DIR/#  #" | sort

( cd build && zip -rq "../$ZIP_PATH" "$PLUGIN_SLUG" -x "*.DS_Store" )

echo ""
echo "Built: $ZIP_PATH"
unzip -l "$ZIP_PATH" | tail -1
