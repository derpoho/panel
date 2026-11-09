# A fresh Ubuntu 24.04 "VPS" for testing ServerOS locally: real systemd (the
# daemon installs as a systemd service), its own Docker for deploys, and SSH
# for backup receivers. Built and started by docker/test-node/up.sh.
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    container=docker

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        systemd systemd-sysv dbus curl sudo ca-certificates iproute2 \
        docker.io docker-compose-v2 openssh-server \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    # Consoles and other bits a container doesn't have.
    && systemctl mask getty.target systemd-logind.service \
    && systemctl enable docker ssh

# Docker inside Docker: overlay2 on its own volume (up.sh mounts one at
# /var/lib/docker), since overlay can't sit on the container's own overlay.
COPY daemon.json /etc/docker/daemon.json

# Trust this Mac's Herd CA, so https://serveros.test works from inside.
# up.sh copies it in from Herd before building.
COPY herd-ca.pem /usr/local/share/ca-certificates/herd-ca.crt
RUN update-ca-certificates

# The daemon pins this CA when enrolling against the local panel (see
# docker/daemon-local/panel-ca.patch). Keep it across sudo, since the
# panel's install command runs `sudo bash`.
ENV SERVEROS_LOCAL_PANEL_CA=/usr/local/share/ca-certificates/herd-ca.crt
RUN echo 'Defaults env_keep += "SERVEROS_LOCAL_PANEL_CA"' > /etc/sudoers.d/serveros-local \
    && chmod 0440 /etc/sudoers.d/serveros-local \
    && echo 'SERVEROS_LOCAL_PANEL_CA=/usr/local/share/ca-certificates/herd-ca.crt' >> /etc/environment

# serveros.test and gateway.serveros.test point at the Mac, on every boot.
COPY serveros-local-hosts /usr/local/sbin/serveros-local-hosts
COPY serveros-local-hosts.service /etc/systemd/system/serveros-local-hosts.service
RUN chmod +x /usr/local/sbin/serveros-local-hosts \
    && systemctl enable serveros-local-hosts

STOPSIGNAL SIGRTMIN+3
CMD ["/sbin/init"]
