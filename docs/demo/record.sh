#!/usr/bin/env bash
#
# Regenerates docs/demo/demo.gif from docs/demo/demo.tape.
#
# VHS runs in a container (docs/demo/Dockerfile) that drives the host's Docker
# daemon through its socket, so `docker compose up` in the tape starts this
# repository's real stack and every curl in the GIF hits the real app.
#
# Requirements: Docker, and a .env file (cp .env.example .env, then
# php artisan key:generate) with the vendor volume already installed.
#
# Usage: docs/demo/record.sh

set -euo pipefail

cd "$(dirname "$0")/../.."

if [ ! -f .env ]; then
    echo "Create .env first: see 'Run it locally' in the README." >&2
    exit 1
fi

# The daemon resolves bind mounts with its own paths. Docker Desktop on
# Windows exposes the C: drive as /run/desktop/mnt/host/c; elsewhere the
# project path is the same for the shell and for the daemon.
project_dir="$PWD"
case "$(uname -s)" in
    MINGW* | MSYS* | CYGWIN*)
        project_dir="/run/desktop/mnt/host/$(pwd -W | sed -E 's#^([A-Za-z]):/#\L\1/#')"
        ;;
esac

# Start from a stopped stack so the recording shows a real start.
docker compose down

docker build --quiet --tag shortlinks-demo-recorder docs/demo > /dev/null

MSYS_NO_PATHCONV=1 docker run --rm \
    --volume /var/run/docker.sock:/var/run/docker.sock \
    --volume "$project_dir:$project_dir" \
    --workdir "$project_dir" \
    --add-host host.docker.internal:host-gateway \
    shortlinks-demo-recorder docs/demo/demo.tape

docker compose down

echo "Wrote docs/demo/demo.gif ($(($(wc -c < docs/demo/demo.gif) / 1024)) KB)"
