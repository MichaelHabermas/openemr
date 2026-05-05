#!/usr/bin/env bash
# Compatibility wrapper for the Week 2 assignment gate.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

exec "${SCRIPT_DIR}/check-clinical-document.sh"
