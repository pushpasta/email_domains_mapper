#!/bin/sh
# Build the GitHub release notes. Usage: build-release-notes.sh <version> <output-file>
set -eu

version="${1:?version is required}"
output="${2:?output file is required}"

mkdir -p "$(dirname "$output")"

{
  printf '%s\n' "## Install with Composer"
  printf '\n'
  printf '%s\n' '```bash'
  printf 'composer require pushpasta/email_domains_mapper:%s\n' "$version"
  printf '%s\n' '```'
  printf '\n'
  printf '%s\n' "Then copy \`plugins/email_domains_mapper/config.inc.php.dist\` to"
  printf '%s\n' "\`config.inc.php\`, edit your domain mappings, and enable"
  printf '%s\n' "\`email_domains_mapper\` before \`managesieve\` in \$config['plugins']."
  printf '%s\n' "See the [README](https://github.com/pushpasta/email_domains_mapper#configuration)"
  printf '%s\n' "for details."
  printf '\n'
  printf '%s\n' "A source archive is attached to this release for non-Composer installs."
  printf '\n'
  printf '%s\n' "## Changes"
  printf '\n'
  awk -v ver="$version" '
    $0 ~ "^## \\[" ver "\\]" { insec=1; next }
    insec && /^## / { insec=0 }
    insec { print }
  ' CHANGELOG.md
} > "$output"
