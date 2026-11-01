#!/usr/bin/env bash
# Fails when a test names a requirement ID that docs/spec/ does not define.
# A test names an ID in its it(), test() or describe() title, for example it('NUT-003 …').
# The spec defines an ID as **NUT-003** in a list or as a "## TBL-001 …" heading.
#
# Usage: scripts/spec-ids.sh [spec dir] [test dir …]
set -euo pipefail
cd "$(dirname "$0")/.."

spec_dir="${1:-docs/spec}"
shift || true
test_dirs=("$@")
if [ ${#test_dirs[@]} -eq 0 ]; then
  test_dirs=(tests)
  [ -d resources/js ] && test_dirs+=(resources/js)
fi

id='[A-Z]{2,5}-[0-9]{3}'

defined=$(grep -rhoE "\*\*${id}\*\*|^#+ ${id}" "$spec_dir" | grep -oE "$id" | sort -u)

named=$(grep -rhoE "\b(it|test|describe)\(\s*['\"\`][^'\"\`]*" "${test_dirs[@]}" \
  --include='*.php' --include='*.ts' --include='*.js' 2>/dev/null \
  | grep -oE "\b${id}\b" | sort -u || true)

unknown=$(comm -13 <(echo "$defined") <(echo "$named") | sed '/^$/d')

if [ -n "$unknown" ]; then
  echo "Tests name requirement IDs that are not in $spec_dir:"
  while read -r missing; do
    echo "  $missing: $(grep -rlE "\b(it|test|describe)\(\s*['\"\`][^'\"\`]*\b${missing}\b" "${test_dirs[@]}" | tr '\n' ' ')"
  done <<<"$unknown"
  exit 1
fi

echo "spec IDs: $(echo "$named" | sed '/^$/d' | wc -l | tr -d ' ') named in tests, all defined in $spec_dir"
