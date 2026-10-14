#!/usr/bin/env bash
set -euo pipefail

version="${1:?usage: $0 <version, such as v1.0.0, or Unreleased>}"
version="${version#v}"

awk -v heading="## [$version]" '
    index($0, heading) == 1 && (length($0) == length(heading) || substr($0, length(heading) + 1, 1) == " ") { found = 1; next }
    found && (/^## \[/ || /^\[[^]]+\]: /) { exit }
    found { lines[++count] = $0 }
    END {
        if (!found) {
            exit 1
        }

        first = 1
        while (first <= count && lines[first] ~ /^[[:space:]]*$/) first++
        last = count
        while (last >= first && lines[last] ~ /^[[:space:]]*$/) last--

        if (first > last) {
            exit 1
        }

        for (i = first; i <= last; i++) print lines[i]
    }
' "$(dirname "$0")/../CHANGELOG.md"
