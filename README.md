# BmwTokenManager

Small PHP 8+ daemon that keeps a BMW OAuth token fresh.
It reads `token.json`, performs an immediate refresh, writes the updated file atomically, and then keeps the token alive by refreshing before expiry.

## Features

- Immediate token refresh on startup. Aborts on failure.
- Periodic refresh using `expires_at - refresh_margin`.
- Atomic writes to `token.json` with `*.tmp` swap and `0600` permissions.
- Exponential backoff on transient refresh failures.
- Minimal stderr logging for supervision.

- Initial token generation via `fetch-init-token-nodeps.sh`

## Requirements

- PHP 8.1+ with `curl` and `json` extensions.

## Installation

```bash
cp BmwTokenManager.php /usr/local/bin/BmwTokenManager
chmod +x /usr/local/bin/BmwTokenManager
````

## Usage

```bash
BmwTokenManager \
	--token-file=/path/to/token.json \
	--refresh-margin=120
```

Short options:

* `-t` or `--token-file` (default: `token.json`)
* `-m` or `--refresh-margin` seconds before expiry to refresh (default: `120`)

Exit codes:

* `0` normal operation (never exits)
* `1` fatal initialization or refresh failure

Logs appear on `stderr`.

## Token file

### Canonical JSON example

```json
{
  "gcid": "XXXXXXXXXXXXXXXXXXXXXX",
  "token_type": "Bearer",
  "access_token": "XXXXXXXXXXXXXXXXXXXXXX",
  "refresh_token": "XXXXXXXXXXXXXXXXXXXXXX",
  "scope": "openid cardata:streaming:read authenticate_user",
  "expires_in": 3599,
  "id_token": "XXXXXXXXXXXXXXXXXXXXXX",
  "fetched_at": 1234567890,
  "expires_at": 1234567890,
  "client_id": "XXXXXXXXXXXXXXXXXXXXXX",
  "vin": "XXXXXXXXXXXXXXXXXXXXXX"
}
```

### Field reference

* **gcid**: BMW Group Customer ID used as MQTT username. Optional in refresh responses but preserved when present.
* **token_type**: Usually `"Bearer"`.
* **access_token**: Short-lived OAuth access token. Not used by MQTT but present.
* **refresh_token**: Long-lived token used to obtain new `id_token`. **Required** by this program.
* **scope**: Granted scopes string.
* **expires_in**: Seconds until expiry as returned by the last token endpoint call.
* **id_token**: JWT used as MQTT password.
* **fetched_at**: Unix epoch when the last refresh was fetched. Added by this program.
* **expires_at**: Absolute Unix epoch expiry time. Added by this program.
* **client_id**: OAuth client ID used for refresh. **Required** in `token.json`.
* **vin**: Vehicle identifier. Not used by the refresher itself; preserved if present.

Notes:

* The program merges new fields into the existing JSON to preserve metadata like `gcid` and `vin`.
* Writes are atomic and set file mode `0600`.

## Systemd example

```ini
[Unit]
Description=BMW Token Manager
After=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/BmwTokenManager --token-file=/etc/bmw/token.json --refresh-margin=120
Restart=always
RestartSec=5s
User=bmw
Group=bmw
UMask=0077

[Install]
WantedBy=multi-user.target
```

## Security

* Ensure `token.json` is owned by a dedicated user and not world-readable.
* The program enforces `0600` on writes but does not change existing ownership.
