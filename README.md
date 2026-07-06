# AllStar Cockpit

AllStar Cockpit is a lightweight web dashboard for one ASL3 / AllStarLink node.

It watches the node and shows live activity, current connections, downstream nodes, IAX links, and recent history. It does not connect, disconnect, restart, or control the node.

AllStar Cockpit is built for ASL3 systems using Apache, PHP, and Asterisk/app_rpt. It has been tested on Debian 12 and Debian 13 on 64-bit ARM, including Raspberry Pi 4 and Raspberry Pi 5. It should also work on similar Debian-based Linux systems running ASL3, but other distributions are not the primary target.  Current version: **0.1.94-dev**

<br>

<p align="center">
  <a href="screenshot.png">
    <img src="screenshot.png" alt="AllStar Cockpit dashboard screenshot" width="850">
  </a>
</p>

## What it shows

- Live key-up activity
- Current AllStar, EchoLink, and IAX connections
- Local connection and transmit history
- Downstream nodes when Asterisk or AllStar status reports them
- Callsign and node details when they are available

## What it does not do

- It does not connect or disconnect nodes.
- It does not restart Asterisk.
- It does not restart DVSwitch.
- It does not edit Asterisk, DVSwitch, or AllStar configuration.
- It does not replace AllTune2, AllScan, AllMon, or DVSwitch Cockpit.

## Installation

Use this only for a brand-new install:

```bash
cd /var/www/html
sudo git clone https://github.com/TerryClaiborne/allstar_cockpit.git allstar_cockpit
cd allstar_cockpit
sudo ./setup_allstar_cockpit.sh
```

Then edit the config file:

```bash
sudo nano /var/www/html/allstar_cockpit/config.ini
```

Set your local AllStar node:

```ini
MYNODE="YOUR_ALLSTAR_NODE"
```

Example:

```ini
MYNODE="67040"
```

Open the dashboard:

```text
http://NODE-IP/allstar_cockpit/public/
```

If you have HTTPS and a domain name configured, use that instead:

```text
https://YOUR-DOMAIN/allstar_cockpit/public/
```

## Updating

For most updates:

```bash
cd /var/www/html/allstar_cockpit
git pull origin main
```

If the update changes setup, permissions, Apache rules, or sudoers, run setup again after pulling:

```bash
cd /var/www/html/allstar_cockpit
git pull origin main
sudo ./setup_allstar_cockpit.sh
```

Setup is meant to preserve your existing `config.ini`, login settings, logs, history, cache, and runtime files.

## Basic configuration

Most users only need these settings in `config.ini`:

```ini
MYNODE="YOUR_ALLSTAR_NODE"
HIDE_NODES=""
USE_SUDO_HELPER=1
POLL_INTERVAL_SECONDS=1
HISTORY_LIMIT=250
```

`MYNODE` is your local AllStar node number. This must be set. For example: `MYNODE="67040"`.

`HIDE_NODES` is optional. Use it for private or local bridge nodes you do not want shown on the dashboard.

`USE_SUDO_HELPER` should normally stay set to `1`. Apache/PHP usually cannot read Asterisk directly, so the dashboard uses the installed read-only helper.

`POLL_INTERVAL_SECONDS` controls dashboard refresh speed. The normal value is `1`.

`HISTORY_LIMIT` controls how many local history entries the API returns. The normal value is `250`.

Advanced settings are in `config.ini.example`, but most users should not need to change them.

## AllStar, EchoLink, and IAX notes

### AllStar

AllStar connections usually show the node number, callsign, mode, direction, and link state when that information is available.

### EchoLink

EchoLink node numbers are not the same as AllStarLink node numbers. EchoLink entries should not be treated as AllStar nodes.

EchoLink details depend on what Asterisk exposes.

### IAX

IAX connections show when the dashboard can read Asterisk IAX status.

For normal Apache/PHP installs, this requires:

```ini
USE_SUDO_HELPER=1
```

The setup script installs the read-only sudo helper needed for this.

The helper only reads status. It does not connect, disconnect, restart, or control Asterisk.

## Optional login

Login is disabled by default because AllStar Cockpit is read-only.

Default mode:

```text
No Login / Normal
```

Enable login and set or change the admin password:

```bash
sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --set-admin-password
```

Disable login:

```bash
sudo /var/www/html/allstar_cockpit/setup_allstar_cockpit.sh --disable-auth
```

Current protected action:

- Clear Downstream History

Normal viewing remains available unless future protected features are added.
