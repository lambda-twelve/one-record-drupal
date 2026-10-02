#!/usr/bin/env bash
# Links the module in this repository into the development site as
# web/modules/contrib/one_record, one symlink per top-level entry.
#
# A single symlink to the repository would put the development site inside
# the module, and Drupal's extension scans (the PHPUnit bootstrap included)
# follow symlinks without limit. Linking entry by entry keeps the site out.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
repo="$(dirname "$here")"
target="$here/web/modules/contrib/one_record"
mkdir -p "$target"
find "$target" -maxdepth 1 -mindepth 1 -type l -delete
for entry in "$repo"/* "$repo"/.[!.]*; do
  name="$(basename "$entry")"
  case "$name" in
    .dev|.ddev|.git|.github|.gitignore|.gitattributes|.phpcs.cache|.phpunit.cache|.cache|ref) continue ;;
  esac
  ln -s "../../../../../$name" "$target/$name"
done
echo "Linked $(ls -A "$target" | wc -l | tr -d ' ') entries into $target"
