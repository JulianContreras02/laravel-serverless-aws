# Recorder for docs/demo/demo.gif: VHS plus the tools the demo runs.
# Built and used by docs/demo/record.sh only; it is not part of the application.

FROM docker:29-cli AS docker

FROM ghcr.io/charmbracelet/vhs:v0.12.1

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl jq socat \
    && rm -rf /var/lib/apt/lists/*

# The Docker CLI talks to the host's daemon through the mounted socket.
COPY --from=docker /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker /usr/local/libexec/docker/cli-plugins/docker-compose /usr/local/libexec/docker/cli-plugins/docker-compose
