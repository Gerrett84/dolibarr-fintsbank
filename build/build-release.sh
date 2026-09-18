#!/bin/bash
# Builds a Dolibarr-installable release ZIP for a given git tag.
#
# Why this exists: fintsbank depends on nemiah/php-fints via Composer
# (vendor/ is gitignored). GitHub's auto-generated "Source code (zip)" for a
# release does NOT include vendor/, so an admin without console/SSH access
# could not run `composer install` themselves after uploading it. This script
# bundles vendor/ into the ZIP so the module works right after upload via
# Dolibarr's module installer (Setup -> Module -> Externes Modul hochladen).
#
# It also fixes the internal folder name: GitHub names the extracted folder
# "dolibarr-fintsbank-<version>" (matches the repo name), but Dolibarr's
# installer expects a folder matching this module's runtime name "fintsbank"
# (see $this->rights_class in core/modules/modFintsBank.class.php). This
# script packs the module under "fintsbank/" so the installer accepts it and
# deploys it to the path the module's own code expects.
#
# Usage:
#   ./build/build-release.sh v2.3.2
#
# Requires: git, composer, php with the zip extension. Run from anywhere
# inside a clean checkout of this repo (working tree must be clean; the
# script builds straight from the given tag, not from local edits).
#
# After building, upload with:
#   gh release upload <tag> fintsbank-<version>.zip -R Gerrett84/dolibarr-fintsbank

set -euo pipefail

TAG="${1:?Usage: $0 <git-tag>}"
MODULE=fintsbank
VERSION="${TAG#v}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$(pwd)"

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

echo "==> Exporting $TAG from git..."
git -C "$REPO_ROOT" archive --format=tar --prefix="$MODULE/" "$TAG" | tar -x -C "$BUILD_DIR"

echo "==> Installing production Composer dependencies..."
(
    cd "$BUILD_DIR/$MODULE"
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-interaction
)

echo "==> Packing ZIP..."
OUT_FILE="$OUT_DIR/$MODULE-$VERSION.zip"
php "$REPO_ROOT/build/make_zip.php" "$BUILD_DIR/$MODULE" "$OUT_FILE"

echo "Done: $OUT_FILE"
