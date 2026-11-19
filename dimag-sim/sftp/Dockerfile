#ddev-generated
# SFTP drop for dimag-sim: OpenSSH with chrooted, password-only SFTP accounts.
FROM alpine:3.20

RUN apk add --no-cache openssh-server \
 && mkdir -p /var/run/sshd /etc/ssh/keys

COPY sshd_config /etc/ssh/sshd_config
COPY entrypoint.sh /entrypoint.sh
RUN chmod 755 /entrypoint.sh

EXPOSE 22
ENTRYPOINT ["/entrypoint.sh"]
