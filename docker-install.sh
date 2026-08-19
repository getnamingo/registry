#!/usr/bin/env bash
set -Eeuo pipefail

repository=${NAMINGO_REPOSITORY:-https://github.com/getnamingo/registry.git}
reference=${NAMINGO_VERSION:-main}

if [[ ${EUID:-$(id -u)} -eq 0 ]]; then
    install_dir=${NAMINGO_INSTALL_DIR:-/opt/namingo-registry}
else
    install_dir=${NAMINGO_INSTALL_DIR:-${HOME}/namingo-registry}
fi

command -v git >/dev/null 2>&1 || { echo "git is required." >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "Docker Engine with Compose v2 is required." >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { echo "Docker Compose v2 is required." >&2; exit 1; }

if [[ -e "$install_dir" ]]; then
    echo "Installation target already exists: $install_dir" >&2
    exit 1
fi

git clone --depth 1 --branch "$reference" "$repository" "$install_dir"
exec "$install_dir/namingo" install

