#!/bin/sh
set -eu
/sbin/modprobe sd_mod
/sbin/modprobe usb-storage
logger -t usg-services 'USB mass-storage drivers loaded; configured storage is mounted by the following startup hook'
