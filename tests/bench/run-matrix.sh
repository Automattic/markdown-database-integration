#!/usr/bin/env bash
#
# MDI bench matrix driver.
#
# Runs the canonical workload set under tests/bench/workloads/ across three
# substrates against ONE component (markdown-database-integration itself).
# The substrate axis is controlled from outside the dispatcher:
#
#   1. SDI control       — MDI's db.php is temp-parked, so WordPress
#                          Playground falls back to its bundled
#                          sqlite-database-integration mu-plugin.
#                          MDI's plugin code still loads but is inert
#                          for the workload paths (the_content + REST
#                          filters never fire on wp_insert_post,
#                          WP_Query, get_post — the workload surface).
#
#   2. MDI mirror        — db.php in place, MARKDOWN_DB_MODE='mirror'
#                          injected via wp_config_defines.
#
#   3. MDI primary       — db.php in place, MARKDOWN_DB_MODE='primary'
#                          injected via wp_config_defines.
#
# Why three Playground instances instead of three substrate components:
# the substrate matrix is fundamentally a "how do we boot WordPress" axis,
# not a "which component is under test" axis. Constructing three on-disk
# components each declaring its own state (with proxy db.php files,
# Requires Plugins headers, separate homeboy.json registration) was
# indirection that didn't carry weight. One component, three boot
# configurations, three Playground processes — each one cleanly isolated
# in PHP-WASM.
#
# Substitute homeboy#1525 (rig matrix) for this script when it lands —
# the matrix declaration would move into a rig spec and homeboy core
# would orchestrate the three cells natively.
#
# USAGE
#
#   bash tests/bench/run-matrix.sh [--iterations N] [BENCH_CORPUS_SIZE=N]
#
#   bash tests/bench/run-matrix.sh --iterations 5
#   BENCH_CORPUS_SIZE=1000 bash tests/bench/run-matrix.sh --iterations 10
#
# All passthrough flags (--iterations, --baseline, --shared-state, --concurrency,
# --regression-threshold) are forwarded to `homeboy bench` via "$@".
#
# Results land at tests/bench/results/<YYYY-MM-DD>/<substrate>.json.
#
# Exit: 0 = all three cells produced an envelope; non-zero = at least one
#       failed. Failed cells emit an empty envelope and a stderr message
#       so the operator can see which substrate boot-failed.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# tests/bench → tests → plugin root
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Verify we are at MDI's root. The substrate-parking step renames db.php
# in place; getting the wrong directory would corrupt an unrelated repo.
if [ ! -f "$PLUGIN_ROOT/markdown-database-integration.php" ]; then
    echo "ERROR: $PLUGIN_ROOT does not look like the MDI plugin root" >&2
    echo "       (no markdown-database-integration.php found)" >&2
    exit 1
fi

if [ ! -f "$PLUGIN_ROOT/db.php" ]; then
    echo "ERROR: $PLUGIN_ROOT/db.php missing — cannot run any cell" >&2
    exit 1
fi

DATE_DIR="$SCRIPT_DIR/results/$(date +%F)"
mkdir -p "$DATE_DIR"

DB_PHP="$PLUGIN_ROOT/db.php"
DB_PHP_PARKED="$PLUGIN_ROOT/db.php.bench-parked"

# Cleanup: if a prior run died mid-SDI cell, db.php may be parked. Restore
# before we do anything else so this script is idempotent.
if [ -f "$DB_PHP_PARKED" ]; then
    if [ -f "$DB_PHP" ]; then
        echo "WARNING: both db.php and db.php.bench-parked exist." >&2
        echo "         Removing db.php.bench-parked (db.php takes precedence)." >&2
        rm "$DB_PHP_PARKED"
    else
        echo "Restoring db.php from db.php.bench-parked (prior run interrupted)." >&2
        mv "$DB_PHP_PARKED" "$DB_PHP"
    fi
fi

restore_db_php() {
    if [ -f "$DB_PHP_PARKED" ] && [ ! -f "$DB_PHP" ]; then
        mv "$DB_PHP_PARKED" "$DB_PHP"
    fi
}
trap restore_db_php EXIT

run_cell() {
    local substrate="$1"
    shift
    local result_file="$DATE_DIR/$substrate.json"

    echo ""
    echo "============================================"
    echo "  Cell: $substrate"
    echo "  Output: $result_file"
    echo "============================================"

    # `--output` is a global flag on `homeboy`. It must be placed BEFORE the
    # `bench` subcommand — the post-subcommand position is silently swallowed
    # by clap's trailing-var-arg capture (Extra-Chill/homeboy#1532). The
    # global position is the documented-correct one; this isn't a workaround,
    # just the syntax that actually works today.
    if homeboy --output "$result_file" bench markdown-database-integration "$@"; then
        echo "✓ $substrate: complete"
        return 0
    else
        echo "✗ $substrate: failed (see output above and $result_file)" >&2
        return 1
    fi
}

# ---------------------------------------------------------------------------
# Cell 1: SDI control
#
# Park MDI's db.php so Playground's bundled SDI mu-plugin (which has a
# self-deactivation guard `if (file_exists('/wordpress/wp-content/db.php'))
# return;`) is the one that wires up $wpdb. MDI plugin code still loads
# but its hot-path filters (the_content, rest_prepare_*) don't fire on
# our workloads, so this isolates the SDI substrate cleanly.
# ---------------------------------------------------------------------------
mv "$DB_PHP" "$DB_PHP_PARKED"
sdi_status=0
run_cell "sdi" "$@" || sdi_status=$?
mv "$DB_PHP_PARKED" "$DB_PHP"

# ---------------------------------------------------------------------------
# Cell 2: MDI mirror
#
# wp_config_defines injects MARKDOWN_DB_MODE='mirror' into wp-tests-config.php
# during pg_run_boot_stage, before MDI's db.php drop-in loads. The dispatcher
# reads the setting from HOMEBOY_SETTINGS_JSON; we synthesize it here so the
# cell doesn't depend on a per-component homeboy.json (this script is the
# orchestration; the component being benched is plain MDI).
# ---------------------------------------------------------------------------
mirror_status=0
HOMEBOY_SETTINGS_JSON='{"wp_config_defines":{"MARKDOWN_DB_MODE":"mirror"}}' \
    run_cell "mirror" "$@" || mirror_status=$?

# ---------------------------------------------------------------------------
# Cell 3: MDI primary
# ---------------------------------------------------------------------------
primary_status=0
HOMEBOY_SETTINGS_JSON='{"wp_config_defines":{"MARKDOWN_DB_MODE":"primary"}}' \
    run_cell "primary" "$@" || primary_status=$?

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "============================================"
echo "  Matrix complete"
echo "============================================"
echo "  sdi:     $([ $sdi_status -eq 0 ] && echo OK || echo FAIL)"
echo "  mirror:  $([ $mirror_status -eq 0 ] && echo OK || echo FAIL)"
echo "  primary: $([ $primary_status -eq 0 ] && echo OK || echo FAIL)"
echo "  Results: $DATE_DIR"

if [ $sdi_status -ne 0 ] || [ $mirror_status -ne 0 ] || [ $primary_status -ne 0 ]; then
    exit 1
fi
