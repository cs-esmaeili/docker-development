#!/usr/bin/env bash
# Put `php` (default version) and `phprun` (any version) on the host PATH.
#
#   bin/install.sh            link php + phprun, prepend the dir to PATH
#   bin/install.sh --no-rc    only create the symlinks
#   bin/install.sh --force    replace existing files (they are backed up)
#   bin/install.sh --remove   undo everything this script added
#
# Override the link directory with INSTALL_DIR=/some/dir.
set -euo pipefail

BIN_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
source "$BIN_DIR/_common.sh"

MARK_BEGIN="# >>> docker-php shims (managed by bin/install.sh) >>>"
MARK_END="# <<< docker-php shims <<<"
FORCE=0
REMOVE=0
WRITE_RC=1

for arg in "$@"; do
  case "$arg" in
    -f|--force)  FORCE=1 ;;
    -r|--remove) REMOVE=1 ;;
    --no-rc)     WRITE_RC=0 ;;
    -h|--help)   sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)           echo "unknown option: $arg (see --help)" >&2; exit 1 ;;
  esac
done

# Real login-shell rc files that exist on this machine.
rc_files=()
for rc in "$HOME/.zshrc" "$HOME/.bashrc"; do
  [ -f "$rc" ] && rc_files+=("$rc")
done
if [ "${#rc_files[@]}" -eq 0 ] && [ -f "$HOME/.profile" ]; then
  rc_files+=("$HOME/.profile")
fi

if [ "$REMOVE" = "1" ]; then
  for dst in "$DPHP_BIN_DIR"/*; do
    [ -L "$dst" ] || continue
    case "$(readlink "$dst")" in
      "$BIN_DIR"/*) rm -f "$dst"; echo "removed $dst" ;;
    esac
  done
  for rc in "${rc_files[@]}"; do
    if grep -qF "$MARK_BEGIN" "$rc"; then
      tmp="$(mktemp)"
      awk -v b="$MARK_BEGIN" -v e="$MARK_END" '
        $0 == b { skip = 1; next }
        $0 == e { skip = 0; next }
        skip != 1 { print }
      ' "$rc" > "$tmp"
      cat "$tmp" > "$rc"
      rm -f "$tmp"
      echo "cleaned PATH entry from $rc"
    fi
  done
  rmdir "$DPHP_BIN_DIR" 2>/dev/null || true
  exit 0
fi

mkdir -p "$DPHP_BIN_DIR"

for name in php phprun; do
  src="$BIN_DIR/$name"
  dst="$DPHP_BIN_DIR/$name"

  if [ -L "$dst" ] && [ "$(readlink "$dst")" = "$src" ]; then
    continue
  fi

  if [ -e "$dst" ] || [ -L "$dst" ]; then
    if [ "$FORCE" != "1" ]; then
      echo "skip $dst (already exists — rerun with --force to replace it)" >&2
      continue
    fi
    backup="$dst.bak.$(date +%Y%m%d%H%M%S)"
    mv "$dst" "$backup"
    echo "backed up $dst -> $backup"
  fi

  ln -s "$src" "$dst"
  echo "linked $dst -> $src"
done

if [ "$WRITE_RC" = "1" ]; then
  for rc in "${rc_files[@]}"; do
    grep -qF "$MARK_BEGIN" "$rc" && continue
    {
      echo ""
      echo "$MARK_BEGIN"
      echo "export PATH=\"$DPHP_BIN_DIR:\$PATH\""
      echo "unalias php composer 2>/dev/null || true"
      echo "hash -r 2>/dev/null || true"
      echo "$MARK_END"
    } >> "$rc"
    echo "added PATH entry to $rc"
  done
else
  case ":$PATH:" in
    *":$DPHP_BIN_DIR:"*) ;;
    *) echo "NOTE: add to PATH:  export PATH=\"$DPHP_BIN_DIR:\$PATH\"" ;;
  esac
fi

cat <<EOF

Done. Open a new shell (or source ${rc_files[0]:-$HOME/.zshrc}), then:

  php -v                     # default $(_dphp_default_version) (global/.env)
  phprun 8.2 -v              # any version, installed on demand
  phprun 8.2 -- composer install

Host PHP escape hatch: PHP_HOST=1 php -v
EOF
