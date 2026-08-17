#!/usr/bin/env sh
# Fail if git commits contain Cursor agent Co-authored-by trailers (REQ-GIT-001).
#
# Scans only the revision range you care about (preferred for CI), not necessarily
# the entire reachable history. Grandfathered trailers already on the default branch
# are ignored when the range excludes them — no history rewrite required.
#
# Usage:
#   ./.scripts/check-no-cursor-coauthor.sh [revision-range]
#
# revision-range:
#   - Git log range, e.g. origin/main..HEAD or <before>..<sha> (push/PR commits only)
#   - Single ref (e.g. HEAD) — all commits reachable from that ref (full-history audit)
#   - Omitted — auto: origin/main..HEAD or origin/master..HEAD when that remote
#     ref exists; otherwise HEAD
set -eu

ZERO_SHA='0000000000000000000000000000000000000000'

resolve_auto_range() {
  for b in main master; do
    if git rev-parse --verify "origin/${b}" >/dev/null 2>&1; then
      echo "origin/${b}..HEAD"
      return 0
    fi
  done
  echo "HEAD"
}

RANGE="${1:-}"
if [ -z "${RANGE}" ]; then
  RANGE="$(resolve_auto_range)"
fi

if [ ! -d .git ]; then
  echo "ERROR: .git not found — run from the bundle repository root (REQ-GIT-001)." >&2
  echo "GitLab-only copies synced without .git must be cloned or re-synced with git history." >&2
  exit 1
fi

TOPLEVEL="$(git rev-parse --show-toplevel 2>/dev/null || true)"
HERE="$(cd "$(dirname "$0")/.." && pwd -P)"
if [ -n "${TOPLEVEL}" ] && [ "$(cd "${TOPLEVEL}" && pwd -P)" != "${HERE}" ]; then
  echo "ERROR: git toplevel is not this bundle (found: ${TOPLEVEL}, expected: ${HERE})" >&2
  echo "Do not run from a parent monorepo checkout without a bundle-local .git." >&2
  exit 1
fi

# Validate range endpoints (git rev-parse --verify does not accept A..B as one rev).
case "${RANGE}" in
  *...*)
    LEFT="${RANGE%%...*}"
    RIGHT="${RANGE##*...}"
    ;;
  *..*)
    LEFT="${RANGE%%..*}"
    RIGHT="${RANGE##*..}"
    ;;
  *)
    LEFT=""
    RIGHT="${RANGE}"
    ;;
esac

if [ -n "${LEFT}" ] && [ "${LEFT}" != "${ZERO_SHA}" ]; then
  if ! git rev-parse --verify "${LEFT}" >/dev/null 2>&1; then
    echo "ERROR: git ref not found (range start): ${LEFT}" >&2
    exit 1
  fi
fi

if [ -z "${RIGHT}" ]; then
  echo "ERROR: empty revision range end: ${RANGE}" >&2
  exit 1
fi

if ! git rev-parse --verify "${RIGHT}" >/dev/null 2>&1; then
  # First commit: no history to scan yet (commit-msg still blocks Cursor trailers).
  if [ "${RIGHT}" = "HEAD" ] && ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    echo "OK: empty repository (no commits yet); skipping history scan"
    exit 0
  fi
  echo "ERROR: git ref not found (range end): ${RIGHT}" >&2
  exit 1
fi

# New-branch push (before = zeros): treat as single-sided log of RIGHT.
LOG_RANGE="${RANGE}"
if [ "${LEFT}" = "${ZERO_SHA}" ]; then
  LOG_RANGE="${RIGHT}"
fi

if [ -z "$(git --no-replace-objects rev-list --max-count=1 "${LOG_RANGE}" 2>/dev/null || true)" ]; then
  echo "OK: no commits in range ${LOG_RANGE}; skipping Cursor co-author scan"
  exit 0
fi

PATTERN='(^Co-authored-by: Cursor <cursoragent@cursor.com>$|^Co-authored-by:.*cursoragent@cursor\.com| Co-authored-by: Cursor <cursoragent@cursor.com>| Co-authored-by:.*cursoragent@cursor\.com)'

# Use --no-replace-objects so local `git replace` refs cannot hide dirty history
# that CI (fresh clone) would still see.
MATCHES="$(
  git --no-replace-objects log "${LOG_RANGE}" --format=%B \
    | grep -E "${PATTERN}" \
    || true
)"

if [ -n "${MATCHES}" ]; then
  echo "ERROR: Cursor co-author trailers found in git history (range: ${LOG_RANGE})" >&2
  echo "Offending commits:" >&2
  git --no-replace-objects log "${LOG_RANGE}" --format='%H %s' | while read -r hash subject; do
    if git --no-replace-objects log -1 --format=%B "${hash}" | grep -qE 'cursoragent@cursor\.com'; then
      echo "  ${hash} ${subject}" >&2
    fi
  done
  echo "Run: git --no-replace-objects log ${LOG_RANGE} --format=%B | grep -i co-authored-by" >&2
  echo "${MATCHES}" | head -5 >&2
  exit 1
fi

echo "OK: no Cursor co-author trailers in scanned commits (range: ${LOG_RANGE})"
