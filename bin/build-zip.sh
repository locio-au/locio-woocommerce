#!/bin/sh
# Builds build/locio-address-autocomplete.zip from the committed tree: what
# wordpress.org and a manual upload receive. .gitattributes export-ignore
# keeps tests and tooling out.
set -eu
cd "$(dirname "$0")/.."
slug=locio-address-autocomplete
ref="${1:-HEAD}"
mkdir -p build
git archive --format=zip --prefix="$slug/" -o "build/$slug.zip" "$ref"
echo "build/$slug.zip"
