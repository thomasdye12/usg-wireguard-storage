# USG WireGuard & USB storage

Reference customizations for using a Ubiquiti USG Pro 4 as a standalone WireGuard server and USB file server behind an existing router.

Includes a PHP dashboard with peer status and disk capacity, a token-authenticated peer provisioning API, SFTP storage mount hooks, and an HTTP disk health endpoint. Connection details are collapsed by default. The package contains no deployment keys, certificates, hostnames, client records, disk UUIDs, or authentication-service registrations.

This is a source reference, **not a firmware image or unattended installer**. The original implementation ran on EdgeOS 4.4.57, Linux 3.10.107, PHP 5.4 and Python 2.7 on MIPS. These are legacy runtimes. Generic paths and configuration changes in this repository have not been deployed end-to-end on a fresh device.

## Layout

| Repository | Device destination |
| --- | --- |
| `web/` | `/config/usg-services/web/` |
| `scripts/` | `/config/scripts/` |
| `post-config.d/` | `/config/scripts/post-config.d/` |
| `config/lighttpd.conf` | `/config/usg-services/lighttpd.conf` |
| `config/tls/10-ssl.conf` | `/config/usg-services/tls/10-ssl.conf` |
| `config/usg-sudoers` | `/etc/sudoers.d/50-usg-services` |

## Preparation

1. Back up the device configuration and original lighttpd configuration. Keep a recovery connection available. Configure the LAN bridge, upstream DHCP, WireGuard interface and firewall manually for your network. This package does not change networking or disable controller adoption.
2. Install a WireGuard package compatible with the exact firmware/kernel/architecture. No third-party binary or firmware is bundled. Provide `wg`, PHP CGI, Python 2.7, `flock`, `blkid`, `modprobe`, and lighttpd with FastCGI and TLS support.
3. Use `br0` for the LAN and `wg0` for WireGuard. This source assumes these interface names and the EdgeOS `ubnt` account. The provisioning helper supports an IPv4 `/24` and allocates unused addresses from it; all existing peers must use unique `/32` addresses. Set the interface address, listen port and DNS service consistently with the metadata.
4. Copy `config/server-public.json.example` to `/config/usg-services/server-public.json` and configure it. `192.0.2.0/24` is documentation-only: replace it with your chosen VPN network. Set your endpoint and freshly generated server public key locally. Keep private keys outside the web root. Set client routes explicitly; IPv6 routing is not configured by this package.
5. Create `/config/usg-services/state` owned by `ubnt` with mode `0700`. Generate a unique 64-character hexadecimal API token locally (for example `openssl rand -hex 32`), store it in `/config/usg-services/api.token`, owned by `root:www-data` with mode `0640`. Do not put credentials in Git.
6. Install your own certificate/private-key PEM bundle at `/config/usg-services/tls/server.pem`, root-owned mode `0600`. Adapt the sample lighttpd TLS settings to the installed version and security requirements. The web hook expects an original config backup at `/config/usg-services/backups/lighttpd.conf.original`; validate with `lighttpd -tt` before enabling the hook.
7. Own web source and privileged scripts by root; web source must be readable by `www-data`, scripts executable but not writable by web users. Validate the sudoers fragment with `visudo -cf` and install it root-owned mode `0440`. Ensure web users cannot replace scripts or their parent directories. Grant `ubnt` the EdgeOS privileges needed for peer configuration.
8. Keep management ports restricted by the device firewall to trusted LAN/VPN clients. PHP checks source membership in the actual `br0`/`wg0` IPv4 subnets (plus loopback). Do not expose these pages through a reverse proxy without reviewing this model: a local proxy may make all requests appear trusted.

## Routing

The intended topology uses a software LAN bridge and no NAT on this server. The upstream router needs a return route for the VPN subnet through the server's LAN IP, plus UDP port forwarding for remote clients. Reserve the LAN address. Internet egress, upstream NAT, DNS and firewall policy remain the operator's responsibility. A listening interface is not proof of end-to-end connectivity.

## USB storage and SFTP

The USB hook loads `sd_mod` and `usb-storage`. Prepare a USB partition as ext3 separately, after verifying the target disk and backups. No disk-erasing scripts are included. Store its filesystem UUID in `/config/usg-services/storage.uuid`. The mount hook checks for a USB-backed ext3 partition and mounts it at `/srv/usg-files` with `nodev,nosuid`.

Set ownership on the mounted filesystem for the intended SFTP account (the original design uses `ubnt:users`, mode `0750`). SFTP uses the normal SSH server on port 22. No SMB server is included. An additional disk needs its own UUID file, mount directory and adapted hook; the capacity summary and health endpoint currently target one configured filesystem, while the device inventory lists all USB disks.

## HTTP interface

- `/wireguard/`: dashboard, refreshing every 15 seconds.
- `/wireguard/?format=json`: peer/status/storage JSON.
- `/server-info.php`: locally configured public server metadata.
- `POST /add_wireguard_peer.php`, with `X-API-Key`: creates and persists one peer, returning `client` and `server` JSON. The client object includes a newly generated private key; handle the response as a secret. Each call creates a new peer. There is no rollback/delete endpoint; a failure after commit may need manual cleanup. This generic version removes legacy GET/query-token provisioning.
- `/health.php`: GET/HEAD; HTTP 200 with `status: ok` or HTTP 503 on failure. Checks the configured disk UUID, USB backing, read-write ext3 mount and an uncached superblock read. A worker deadline bounds checks to five seconds, with a lock preventing concurrent disk probes. Poll about once a minute with a timeout of at least ten seconds. This is not SMART monitoring or a surface scan.

The dashboard suppresses private/preshared keys in WireGuard dumps. Collapsed UI sections are visual privacy only: authorized clients can still read addresses and public keys in JSON/page data. LAN membership is not individual user authentication. Peer provisioning is privileged and should only be available to trusted operators.

## Validation and limitations

The deployment this source derives from was checked for SSH/SFTP access, reboot mounting, dashboard refresh and health success/failure behavior. Those results do not establish compatibility with other hardware or firmware. Firmware upgrades or controller provisioning can overwrite settings; review persistence and recovery before upgrading. No license for third-party firmware/packages is conveyed by this repository.
