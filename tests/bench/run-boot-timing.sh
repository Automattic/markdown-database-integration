#!/usr/bin/env bash
#
# MDI primary boot timing driver for GitHub issue #44.
#
# Uses homeboy bench as the measurement runner, with a shared-state mount as
# the persistent markdown/index substrate across cold and warm boot phases.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

if [ ! -f "$PLUGIN_ROOT/markdown-database-integration.php" ]; then
    echo "ERROR: $PLUGIN_ROOT does not look like the MDI plugin root" >&2
    exit 1
fi

ITERATIONS=5
CORPUS_SIZE="${BENCH_CORPUS_SIZE:-100}"
LAZY_SAMPLE_SIZE="${BENCH_LAZY_SAMPLE_SIZE:-10}"
STATE_DIR=""
KEEP_STATE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --iterations)
            ITERATIONS="$2"
            shift 2
            ;;
        --corpus-size)
            CORPUS_SIZE="$2"
            shift 2
            ;;
        --lazy-sample-size)
            LAZY_SAMPLE_SIZE="$2"
            shift 2
            ;;
        --shared-state)
            STATE_DIR="$2"
            KEEP_STATE=1
            shift 2
            ;;
        --keep-state)
            KEEP_STATE=1
            shift
            ;;
        -h|--help)
            cat <<'USAGE'
Usage: bash tests/bench/run-boot-timing.sh [options]

Options:
  --iterations N          Measured iterations per phase (default: 5)
  --corpus-size N         Markdown files to seed before cold boot (default: BENCH_CORPUS_SIZE or 100)
  --lazy-sample-size N    Posts to resolve through lazy post_content loading (default: 10)
  --shared-state DIR      Reuse a host shared-state directory and keep it after the run
  --keep-state            Keep the generated shared-state directory after the run
USAGE
            exit 0
            ;;
        *)
            echo "ERROR: unknown argument: $1" >&2
            exit 1
            ;;
    esac
done

DATE_DIR="$SCRIPT_DIR/results/$(date +%F)"
mkdir -p "$DATE_DIR"

if [ -z "$STATE_DIR" ]; then
    STATE_DIR="$DATE_DIR/boot-timing-state"
    rm -rf "$STATE_DIR"
fi

CONTENT_DIR="$STATE_DIR/boot-content"
INDEX_PATH="$STATE_DIR/boot-markdown-index.sqlite"
OBSERVATIONS="$STATE_DIR/boot-timing-observations.jsonl"
SUMMARY_FILE="$DATE_DIR/boot-timing-summary.json"

cleanup() {
    if [ "$KEEP_STATE" -eq 0 ]; then
        rm -rf "$STATE_DIR"
    fi
}
trap cleanup EXIT

seed_corpus() {
    rm -rf "$CONTENT_DIR"
    mkdir -p "$CONTENT_DIR/post" "$CONTENT_DIR/_options"

    cat > "$CONTENT_DIR/_options/siteurl.json" <<'EOF'
{
  "option_id": 1,
  "option_name": "siteurl",
  "option_value": "http://example.org",
  "autoload": "yes"
}
EOF
    cat > "$CONTENT_DIR/_options/home.json" <<'EOF'
{
  "option_id": 2,
  "option_name": "home",
  "option_value": "http://example.org",
  "autoload": "yes"
}
EOF
    cat > "$CONTENT_DIR/_options/blogname.json" <<'EOF'
{
  "option_id": 3,
  "option_name": "blogname",
  "option_value": "MDI Bench",
  "autoload": "yes"
}
EOF

    local i
    for ((i = 1; i <= CORPUS_SIZE; i++)); do
        local slug="bench-$i"
        local file="$CONTENT_DIR/post/$slug.md"
        cat > "$file" <<EOF
---
id: $i
title: "Bench $i"
status: publish
type: post
author: 1
date: "2026-01-01 00:00:00"
modified: "2026-01-01 00:00:00"
slug: $slug
menu_order: 0
comment_status: open
ping_status: open
---

# Bench $i

This is seeded markdown body $i for boot timing. It contains enough content for lazy resolution.
EOF
    done
}

delete_index() {
    rm -f "$INDEX_PATH" "$INDEX_PATH-wal" "$INDEX_PATH-shm" "$INDEX_PATH-journal"
}

bench_env_json() {
    jq -nc \
        --arg only "boot-timing" \
        --arg phase "$1" \
        --arg size "$CORPUS_SIZE" \
        --arg lazy "$LAZY_SAMPLE_SIZE" \
        '{
            BENCH_ONLY:$only,
            BENCH_BOOT_PHASE:$phase,
            BENCH_CORPUS_SIZE:$size,
            BENCH_LAZY_SAMPLE_SIZE:$lazy,
            BENCH_BOOT_CONTENT_DIR:"/bench-shared-state/boot-content",
            BENCH_BOOT_INDEX_PATH:"/bench-shared-state/boot-markdown-index.sqlite"
        }'
}

run_phase() {
    local phase="$1"
    local output="$DATE_DIR/boot-$phase.json"
    echo ""
    echo "============================================"
    echo "  Boot phase: $phase"
    echo "  Output: $output"
    echo "  Corpus: $CORPUS_SIZE"
    echo "============================================"

    HOMEBOY_BENCH_SHARED_STATE="$STATE_DIR" homeboy \
        --output "$output" \
        bench markdown-database-integration \
        --iterations "$ITERATIONS" \
        --setting-json "bench_env=$(bench_env_json "$phase")" \
        --ignore-baseline
}

seed_corpus
delete_index
rm -f "$OBSERVATIONS"

run_phase cold
run_phase warm-noop
run_phase warm-one-file

jq -s \
    --argjson corpus "$CORPUS_SIZE" \
    --argjson iterations "$ITERATIONS" \
    --argjson lazy "$LAZY_SAMPLE_SIZE" \
    '{
        corpus_size: $corpus,
        iterations: $iterations,
        lazy_sample_size: $lazy,
        phases: group_by(.phase) | map({
            phase: .[0].phase,
            observations: length,
            latest_loader_timings: (.[-1].loader_timings),
            latest_loader_stats: (.[-1].loader_stats),
            latest_lazy_content_ms: (.[-1].lazy_content_ms),
            latest_lazy_content_rows: (.[-1].lazy_content_rows),
            markdown_file_count: (.[-1].markdown_file_count),
            index_size_bytes: (.[-1].index_size_bytes)
        })
    }' "$OBSERVATIONS" > "$SUMMARY_FILE"

echo ""
echo "Boot timing complete."
echo "Summary:      $SUMMARY_FILE"
echo "Observations: $OBSERVATIONS"
if [ "$KEEP_STATE" -eq 1 ]; then
    echo "Shared state:  $STATE_DIR"
fi
