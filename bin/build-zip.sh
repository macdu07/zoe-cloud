#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
OUTPUT_DIR="${PLUGIN_DIR}/dist"
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGE_DIR}"' EXIT

mkdir -p "${OUTPUT_DIR}" "${STAGE_DIR}/zoe-cloud"
cd "${PLUGIN_DIR}"
git ls-files -co --exclude-standard -z | while IFS= read -r -d '' file; do
	case "${file}" in
		.github/*|bin/*|tests/*|composer.json|composer.lock|phpcs.xml|phpcs.xml.dist|phpunit.xml.dist|phpstan.neon.dist|phpstan-bootstrap.php|.distignore|.gitignore|.phpunit.result.cache) continue ;;
	esac
	mkdir -p "${STAGE_DIR}/zoe-cloud/$(dirname "${file}")"
	cp "${file}" "${STAGE_DIR}/zoe-cloud/${file}"
done

cd "${STAGE_DIR}"
zip -qr "${OUTPUT_DIR}/zoe-cloud-1.0.0.zip" zoe-cloud
