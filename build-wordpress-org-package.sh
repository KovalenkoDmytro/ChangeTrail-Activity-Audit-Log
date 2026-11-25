#!/bin/sh

set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BUILD_ROOT="$ROOT_DIR/wordpress-org-build"
TRUNK_DIR="$BUILD_ROOT/trunk"
ASSETS_DIR="$BUILD_ROOT/assets"
PACKAGE_SLUG="kovalenko-admin-change-audit"
# ROOT_DIR is the source plugin folder itself and is installable as-is, so it must never be
# deleted or used as a build target. The package is staged under wordpress-org-build/package/<slug>/
# and the zip's top-level folder is the slug.
STAGE_ROOT="$BUILD_ROOT/package"
STAGE_DIR="$STAGE_ROOT/$PACKAGE_SLUG"
ZIP_PATH="$BUILD_ROOT/$PACKAGE_SLUG.zip"

case "$STAGE_DIR" in
    "$BUILD_ROOT"/package/?*) ;;
    *)
        printf '%s\n' "Refusing to build: unexpected staging path: $STAGE_DIR" >&2
        exit 1
        ;;
esac

rm -rf "$TRUNK_DIR"
rm -rf "$ASSETS_DIR"
rm -rf "$STAGE_ROOT"
rm -f "$ZIP_PATH"
mkdir -p "$STAGE_DIR/assets" "$STAGE_DIR/includes" "$STAGE_DIR/languages" "$ASSETS_DIR"

cp "$ROOT_DIR/activity-logger-site-owners.php" "$STAGE_DIR/activity-logger-site-owners.php"
cp "$ROOT_DIR/readme.txt" "$STAGE_DIR/"
cp "$ROOT_DIR/uninstall.php" "$STAGE_DIR/"
cp "$ROOT_DIR/assets/admin.css" "$STAGE_DIR/assets/"
cp "$ROOT_DIR/assets/admin.js" "$STAGE_DIR/assets/"
cp "$ROOT_DIR/assets/device.js" "$STAGE_DIR/assets/"
cp "$ROOT_DIR/includes/"*.php "$STAGE_DIR/includes/"
cp "$ROOT_DIR/languages/index.php" "$STAGE_DIR/languages/"

# WordPress.org SVN trunk uses the same plugin file set as the package.
mkdir -p "$TRUNK_DIR"
cp -R "$STAGE_DIR/." "$TRUNK_DIR/"

cp "$ROOT_DIR/assets/icon-128x128.png" "$ASSETS_DIR/"
cp "$ROOT_DIR/assets/icon-256x256.png" "$ASSETS_DIR/"
cp "$ROOT_DIR/assets/banner-772x250.png" "$ASSETS_DIR/"

(
    cd "$STAGE_ROOT"
    zip -rq "$ZIP_PATH" "$PACKAGE_SLUG"
)

printf '%s\n' "WordPress.org SVN trunk prepared at: $TRUNK_DIR"
printf '%s\n' "Package staging folder prepared at: $STAGE_DIR"
printf '%s\n' "Submission zip prepared at: $ZIP_PATH"
