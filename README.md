# AllStar Cockpit

AllStar Cockpit is a lightweight web dashboard for one ASL3 / AllStarLink node.

It watches the node and shows live activity. It does not control the node.

Current version: **0.1.94-dev**

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
https://YOUR_NODE_HOSTNAME/allstar_cockpit/public/
```

or locally:

```text
https://127.0.0.1/allstar_cockpit/public/
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
