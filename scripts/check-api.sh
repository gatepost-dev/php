#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Gatepost authors
# SPDX-License-Identifier: Apache-2.0
# Compares the public API with the newest release tag (GIT-7, API-13). Before the first tag,
# no released API exists, so the script says so and stops.
#
# The value of Postcode::SPEC_VERSION changes with each spec version by design. That change is
# not a break of the API, so the script drops that one finding. It keeps every other finding,
# and it fails on every other failure of the tool.
set -euo pipefail

# Git sorts v0.1.0-alpha.0 above v0.1.0 unless the config names the suffix. Without the option,
# the check would compare a release with its own alpha.
tag="$(git -c versionsort.suffix=- tag --list 'v*' --sort=-version:refname | head -n 1)"
if [ -z "$tag" ]; then
  echo "No release tag yet, so there is no released API to compare."
  exit 0
fi

spec_version_change='[BC] CHANGED: Value of constant '
spec_version_change+='Gatepost\Postcode\Postcode::SPEC_VERSION changed'
status=0
tool=tools/bc-check/vendor/bin/roave-backward-compatibility-check
output="$("$tool" --from="$tag" 2>&1)" || status=$?

dropped=0
breaks=0
while IFS= read -r line; do
  if [[ $line == "$spec_version_change"* ]]; then
    dropped=1
  elif [[ $line =~ ^[0-9]+\ backwards-incompatible\ changes\ detected$ ]]; then
    : # The tool counts the dropped finding, so the script prints its own count.
  else
    echo "$line"
    if [[ $line == '[BC]'* ]]; then breaks=$((breaks + 1)); fi
  fi
done <<<"$output"

if [ "$breaks" -gt 0 ]; then
  echo "$breaks backwards-incompatible changes detected"
  exit 3
fi
# Code 3 is the code of the tool for a break. Another code, or code 3 with no finding to
# explain it, is a failure of the tool itself.
if [ "$status" -ne 0 ] && { [ "$status" -ne 3 ] || [ "$dropped" -eq 0 ]; }; then
  exit "$status"
fi
