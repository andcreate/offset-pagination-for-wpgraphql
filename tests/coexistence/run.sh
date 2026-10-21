#!/usr/bin/env bash
# 上流版との共存ガードを、有効化の順を変えて確かめる
# Check the coexistence guard with the upstream plugin, in both activation orders.
#
# Usage: tests/coexistence/run.sh [wp-env config file]
set -euo pipefail

CONFIG="${1:-.wp-env.test.json}"
WP_ENV="${WP_ENV:-npx -y @wordpress/env@12.0.0}"
FORK=offset-pagination-for-wpgraphql
UPSTREAM=wp-graphql-offset-pagination
CHECK=wp-content/plugins/${FORK}/tests/coexistence/check.php

# Git Bash が /var/... を書き換えないようにする / Keep Git Bash from rewriting container paths.
export MSYS_NO_PATHCONV=1

wp() {
	${WP_ENV} run cli --config="${CONFIG}" wp "$@"
}

check() {
	echo "--- check: $1"
	wp eval-file "${CHECK}" "$1"
}

reset() {
	wp plugin deactivate "${FORK}" "${UPSTREAM}" --quiet || true
}

echo "=== 1. fork only"
reset
wp plugin activate "${FORK}"
check fork-only

echo "=== 2. fork first, then upstream"
reset
wp plugin activate "${FORK}"
wp plugin activate "${UPSTREAM}"
check both

echo "=== 3. upstream first, then fork"
reset
wp plugin activate "${UPSTREAM}"
wp plugin activate "${FORK}"
check both

echo "=== 4. upstream deactivated again"
wp plugin deactivate "${UPSTREAM}"
check fork-only

echo "All coexistence checks passed."
