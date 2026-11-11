#!/bin/sh

set -eu

TYPESENSE_DATA_DIR="${TYPESENSE_DATA_DIR:-$HOME/.typesense/medcon_database}"
TYPESENSE_API_KEY="${TYPESENSE_API_KEY:-xyz}"

mkdir -p "$TYPESENSE_DATA_DIR" "$HOME/.typesense/logs"

exec typesense-server \
    --data-dir="$TYPESENSE_DATA_DIR" \
    --api-key="$TYPESENSE_API_KEY" \
    --api-address=127.0.0.1 \
    --api-port="${TYPESENSE_PORT:-8108}" \
    --log-dir="$HOME/.typesense/logs"
