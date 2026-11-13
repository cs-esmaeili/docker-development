#!/usr/bin/env bash
# Shared helpers for bin/php and bin/phprun. Sourced, not executed.

DOCKER_DIR="${DOCKER_DIR:-$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)}"
ENV_FILE="$DOCKER_DIR/global/.env"
CTX_DIR="$DOCKER_DIR/global/php"
DPHP_BIN_DIR="${INSTALL_DIR:-$HOME/.local/docker-php/bin}"

_dphp_default_version() {
  local v
  v="$(sed -n 's/^PHP_VERSION=//p' "$ENV_FILE" 2>/dev/null | tail -n1)"
  echo "${v:-8.3}"
}

# 8.3 / 83 / 8 -> 8.3 / 8.3 / 8
_dphp_norm() {
  case "$1" in
    *.*)        echo "$1" ;;
    [0-9][0-9]) echo "${1%?}.${1#?}" ;;
    *)          echo "$1" ;;
  esac
}

# Only X.Y is a real docker tag (php:8-cli would mean "PHP 8.5", never what you want).
_dphp_valid() {
  [[ "$1" =~ ^[0-9]+\.[0-9]+$ ]]
}

_dphp_image()     { echo "infra-php:$1"; }
_dphp_container() { echo "infra-php-$1"; }

_dphp_compose() {
  if docker compose version >/dev/null 2>&1; then
    docker compose -f "$DOCKER_DIR/global/docker-compose.yml" --env-file "$ENV_FILE" "$@"
  else
    docker-compose -f "$DOCKER_DIR/global/docker-compose.yml" --env-file "$ENV_FILE" "$@"
  fi
}

# The network belongs to compose (so compose never warns about it).

# One container per version: infra-php-8.3, infra-php-8.4, ...
# The default version (global/.env PHP_VERSION) is a compose service, so
# `docker compose up -d` brings it up; other versions are created on demand.
_dphp_ensure() {
  local v="$1" name image
  name="$(_dphp_container "$v")"
  image="$(_dphp_image "$v")"

  if [[ "$v" == "$(_dphp_default_version)" ]]; then
    if [ "${PHP_REBUILD:-0}" = "1" ]; then
      echo "php-docker: rebuilding $image ..." >&2
      _dphp_compose build php >&2
      _dphp_compose up -d --force-recreate php >&2
      return 0
    fi
    docker ps --format '{{.Names}}' | grep -qx "$name" && return 0
    _dphp_compose up -d php >&2
    return 0
  fi

  if [ "${PHP_REBUILD:-0}" = "1" ] || ! docker image inspect "$image" >/dev/null 2>&1; then
    echo "php-docker: building $image (may take a few minutes) ..." >&2
    docker build --build-arg "PHP_VERSION=$v" -t "$image" "$CTX_DIR" >&2
    # existing container still points at the old image
    docker rm -f "$name" >/dev/null 2>&1 || true
  fi

  # old container created on the bridge network? recreate it on host network
  if docker ps -a --format '{{.Names}}' | grep -qx "$name"; then
    if [[ "$(docker inspect -f '{{.HostConfig.NetworkMode}}' "$name")" != "host" ]]; then
      docker rm -f "$name" >/dev/null 2>&1 || true
    fi
  fi

  docker ps --format '{{.Names}}' | grep -qx "$name" && return 0

  if docker ps -a --format '{{.Names}}' | grep -qx "$name"; then
    docker start "$name" >/dev/null
    return 0
  fi

  docker run -d --name "$name" --restart unless-stopped \
    --network host \
    -u "${PHP_UID:-1000}:${PHP_GID:-1000}" -w "$HOME" \
    -e "HOME=$HOME" \
    -e "COMPOSER_HOME=$HOME/.composer" \
    -e "COMPOSER_CACHE_DIR=$HOME/.cache/composer" \
    --add-host host.docker.internal:host-gateway \
    -v "$HOME:$HOME" -v /tmp:/tmp \
    "$image" >/dev/null
}

# _dphp_exec <version> <php|composer> [args...]
_dphp_exec() {
  local v="$1" cmd="$2"; shift 2
  local cwd tty=() env=() var

  [ -z "$v" ] && v="$(_dphp_default_version)"
  v="$(_dphp_norm "$v")"
  if ! _dphp_valid "$v"; then
    echo "php-docker: invalid PHP version '$v' — use X.Y, e.g. 8.3 or 8.4" >&2
    exit 2
  fi
  _dphp_ensure "$v"
  cwd="$(pwd -P)"
  [[ -t 0 && -t 1 ]] && tty=(-t)

  for var in COMPOSER_AUTH GITHUB_TOKEN GITLAB_TOKEN XDEBUG_MODE TERM COLUMNS; do
    [[ -n "${!var:-}" ]] && env+=(-e "$var=${!var}")
  done

  # $HOME is mounted with the same path, so cwd/paths map 1:1.
  if [[ "$cwd" == "$HOME" || "$cwd" == "$HOME"/* ]]; then
    exec docker exec -i "${tty[@]}" "${env[@]}" -w "$cwd" \
      "$(_dphp_container "$v")" "$cmd" "$@"
  fi

  # Outside $HOME: one-shot container, same image.
  exec docker run --rm -i "${tty[@]}" "${env[@]}" \
    -u "$(id -u):$(id -g)" -w "$cwd" \
    -e "HOME=$HOME" \
    -e "COMPOSER_HOME=$HOME/.composer" \
    -e "COMPOSER_CACHE_DIR=$HOME/.cache/composer" \
    --network host \
    --add-host host.docker.internal:host-gateway \
    -v "$cwd:$cwd" -v "$HOME:$HOME" -v /tmp:/tmp \
    "$(_dphp_image "$v")" "$cmd" "$@"
}
