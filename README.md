# OPNsense SSH and HTTPS first-access helper

For a manually installed OPNsense lab on Hetzner. Download a file instead of
pasting PHP into the browser console. Checked against OPNsense stable/25.7
configuration source. This is not an installer or cloud-init script.

Status: locally syntax/unit-tested; not yet executed on a live OPNsense router.
The download commands use this repository's `main` branch. Prefer a reviewed
commit ID in place of `main` for a repeatable installation.

## What it does

- Enables root SSH with the existing installation password, on WAN port 22.
- Adds logged WAN IPv4 TCP22 and TCP443 rules from one operator IP to WAN address.
- Preserves existing interfaces, DHCP, routes, hostname, certificates and other rules.
- Replaces its own two rules on repeat runs instead of duplicating them.
- Uses OPNsense's native configuration revision backup and service integration.
- Defaults to dry-run. Applying requires both `--apply` and typing `APPLY`.

It does not configure Hetzner, set/reset passwords, issue certificates, reboot,
install packages, disable PF, or prove that remote connections work. Broader
existing firewall rules remain broader. This is for initial lab management,
not a replacement for reviewing your complete firewall and authentication policy.

## Prerequisites

1. Install OPNsense to disk, set a root password and detach the ISO.
2. Assign WAN/public and LAN/private correctly; use DHCP where Hetzner requires it.
3. Confirm Internet access and DNS resolution from the router.
4. In Hetzner, apply inbound TCP22 and TCP443 rules restricted to your computer's
   actual public IPv4 `/32`. Check every attached firewall; their allowances combine.
5. Keep the Hetzner console open. Preserve an external protected configuration
   backup before mutation if required by your operating policy. Native revisions
   remain on the router and do not replace external recovery copies.

## Download, review, apply

These commands are for console option 8 (Shell). Execute one at a time.
The pathname is on the router, not a Linux workstation/project directory.
`fetch` is FreeBSD's downloader; curl need not be installed.

```sh
fetch -o /root/bootstrap.php https://raw.githubusercontent.com/rfvillacacan/opnsense-ssh-fix/main/bootstrap.php
```

Stop if the download fails. Do not disable HTTPS verification. Before
overwriting an existing `/root/bootstrap.php`, preserve or review that file.
Review the downloaded source and compare its checksum with the trusted
publication record supplied by the maintainer:

```sh
sha256 /root/bootstrap.php
php -l /root/bootstrap.php
```

Use your computer's actual public IPv4; the address below is documentation-only.
First preview:

```sh
php /root/bootstrap.php 192.0.2.10
```

Then apply the same plan:

```sh
php /root/bootstrap.php 192.0.2.10 --apply
```

Check the displayed operator IP, then type `APPLY`. It reuses your current root
password; no password belongs in this repository or command arguments.

If console paste still changes case/punctuation, do not execute the damaged
line. Downloading avoids multiline PHP paste but cannot correct broken keyboard
translation for the short downloader command itself. Type that command once
or use the documented GUI-first lab recovery method instead.

## Verify from your computer

The helper prints SSH host fingerprint and listeners. Require an SSH listener
on port 22 and PF `Status: Enabled`. Replace PUBLIC_IPV4 with your router:

```sh
ssh -o HostKeyAlgorithms=ssh-ed25519 root@PUBLIC_IPV4
```

Compare the SSH fingerprint against the provider console before accepting it.
If an old deleted server used that address, verify the new identity before
removing the old known-host entry. Keep host-key checking enabled.
Open `https://PUBLIC_IPV4` in your browser. An initial self-signed warning is
expected; confirm the assigned server address. Test fresh connections after
a reboot before declaring persistence verified.

## Failure and rollback

An activation command can fail after the configuration was saved. The script
reports that boundary and does not claim success or silently revert unrelated
configuration. Keep the console open and inspect the reported command failure.
Rerunning the helper is idempotent for its own rules, but it still restarts SSH.

Native OPNsense revision backups are retained under `/conf/backup`. Recover the
pre-bootstrap revision through OPNsense's configuration restore interface when
reachable, or follow the official console recovery procedure. Do not publish a
configuration backup: it contains secrets. Existing external backups are the
preferred recovery source. This helper does not erase rules or factory reset.

If you remove bootstrap access manually later, delete only the rules named
`bootstrap operator SSH` and `bootstrap operator HTTPS`, and change SSH settings
through System > Settings > Administration after establishing another working
administrative path.

## Validation scope

PHP 8.3 syntax check and 25 configuration and privilege assertions passed locally in a
network-disabled, read-only test container. Tests cover preserved data, operator
restriction, invalid input, missing WAN, idempotence, and operator replacement.
Linux execution is rejected. The root check uses system id(1), avoiding the optional PHP POSIX extension; tests also run with posix_geteuid disabled. The actual OPNsense save/reload/start and external
SSH/HTTPS authentication still need live acceptance by the operator.

Sources: [OPNsense 25.7 SSH settings](https://github.com/opnsense/core/blob/stable/25.7/src/etc/inc/plugins.inc.d/openssh.inc),
[native service actions](https://github.com/opnsense/core/blob/stable/25.7/src/opnsense/service/conf/actions.d/actions_openssh.conf),
[configuration revision backup](https://github.com/opnsense/core/blob/stable/25.7/src/etc/inc/config.inc),
[Hetzner cloud-init scope](https://docs.hetzner.com/cloud/servers/faq/).
