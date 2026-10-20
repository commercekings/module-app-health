# The tools host-status.sh needs in a container, built once - nothing is installed while it runs, so the container
# needs no network but the proxy's. The script itself is mounted read-only (an update needs no rebuild).
FROM alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc
RUN apk add --no-cache bash jq coreutils docker-cli util-linux
CMD ["/bin/sh", "-c", "while :; do bash /usr/local/bin/host-status.sh || echo host-status failed >&2; sleep 60; done"]
