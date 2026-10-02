#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 The Gatepost authors
# SPDX-License-Identifier: Apache-2.0
# Compares the public API with the newest release tag (GIT-7, API-13). Before the first tag,
# no released API exists, so the script says so and stops.
set -euo pipefail

# Git sorts v0.1.0-alpha.0 above v0.1.0 unless the config names the suffix. Without the option,
# the check would compare a release with its own alpha.
tag="$(git -c versionsort.suffix=- tag --list 'v*' --sort=-version:refname | head -n 1)"
if [ -z "$tag" ]; then
  echo "No release tag yet, so there is no released API to compare."
  exit 0
fi
exec tools/bc-check/vendor/bin/roave-backward-compatibility-check --from="$tag"
