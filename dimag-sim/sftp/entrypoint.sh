#!/bin/sh
#ddev-generated
# Accounts from DIMAG_SFTP_USERS: "user:password:uid[:dir]" separated by
# blanks or semicolons. Each user is chrooted to /home/<user>; <dir>
# (default Import) is the drop shared with the dimag container.
set -eu

[ -f /etc/ssh/keys/ssh_host_ed25519_key ] || ssh-keygen -q -t ed25519 -N '' -f /etc/ssh/keys/ssh_host_ed25519_key
[ -f /etc/ssh/keys/ssh_host_rsa_key ] || ssh-keygen -q -t rsa -b 3072 -N '' -f /etc/ssh/keys/ssh_host_rsa_key

for entry in $(echo "${DIMAG_SFTP_USERS:-inge:inge:1000}" | tr ';' ' '); do
  user=$(echo "$entry" | cut -d: -f1)
  pass=$(echo "$entry" | cut -d: -f2)
  uid=$(echo "$entry" | cut -d: -f3)
  dir=$(echo "$entry" | cut -d: -f4)
  dir=${dir:-Import}
  getent group "$uid" >/dev/null 2>&1 || addgroup -g "$uid" "g$uid"
  group=$(getent group "$uid" | cut -d: -f1)
  id "$user" >/dev/null 2>&1 || adduser -D -H -h "/home/$user" -u "$uid" -G "$group" -s /sbin/nologin "$user"
  echo "$user:$pass" | chpasswd >/dev/null 2>&1
  mkdir -p "/home/$user/$dir"
  chown root:root "/home/$user" && chmod 755 "/home/$user"
  chown "$uid:$uid" "/home/$user/$dir"
done

exec /usr/sbin/sshd -D -e
