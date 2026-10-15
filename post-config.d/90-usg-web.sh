#!/bin/sh
set -eu
conf=/etc/lighttpd/lighttpd.conf
custom=/config/usg-services/lighttpd.conf
if ! cmp -s "$custom" "$conf" || [ "${1-}" = "--reload" ]; then
    cp -p "$custom" "$conf"
    if ! /usr/sbin/lighttpd -tt -f "$conf"; then
        cp -p /config/usg-services/backups/lighttpd.conf.original "$conf"
        logger -t usg-services 'Custom web configuration invalid; original restored'
        exit 1
    fi
    /sbin/start-stop-daemon --stop --quiet --retry TERM/5/KILL/2 --pidfile /var/run/lighttpd.pid --exec /usr/sbin/lighttpd || true
    /sbin/start-stop-daemon --start --quiet --pidfile /var/run/lighttpd.pid --exec /usr/sbin/lighttpd -- -f "$conf"
fi
