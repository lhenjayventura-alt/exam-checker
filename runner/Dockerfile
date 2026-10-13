# Sandbox image for student code. Build: docker build -t exam-checker-runner:1 runner/
#
# Contains only a C toolchain, Python 3 and coreutils (for `timeout`). It has no network
# tools, no package manager cache and no application code. Containers are started by
# App\Services\CodeExecution\DockerCodeRunner with network disabled, a read-only root
# filesystem, an unprivileged user and resource limits.
FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc libc6-dev python3 coreutils \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/* /var/cache/apt/*

# 65534 = nobody; the runner also passes --user 65534:65534 explicitly.
USER 65534:65534
WORKDIR /tmp
