#!/bin/sh
set -eu
uuid=$(cat /config/usg-services/storage.uuid)
case "$uuid" in ????????-????-????-????-????????????) ;; *) exit 1 ;; esac
mkdir -p /srv/usg-files
if awk '$2 == "/srv/usg-files" {found=1} END {exit !found}' /proc/mounts; then exit 0; fi
n=0
dev=''
while [ "$n" -lt 15 ]; do
 dev=$(/sbin/blkid -c /dev/null -t "UUID=$uuid" -o device 2>/dev/null || true)
 [ -z "$dev" ] || break
 n=$((n + 1))
 sleep 2
done
if [ -z "$dev" ]; then
 logger -t usg-services 'USB files disk absent; share path left unmounted'
 exit 0
fi
case "$dev" in /dev/sd[a-z][0-9]*) ;; *) exit 1 ;; esac
case "$(readlink -f /sys/class/block/${dev##*/})" in */usb*) ;; *) exit 1 ;; esac
[ "$(/sbin/blkid -c /dev/null -s TYPE -o value "$dev")" = ext3 ]
mount -t ext3 -o nodev,nosuid "$dev" /srv/usg-files
logger -t usg-services 'Mounted USB files for SFTP access'
