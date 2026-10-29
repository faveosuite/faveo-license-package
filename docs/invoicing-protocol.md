# Faveo Invoicing protocol

What [Faveo Invoicing](https://github.com/faveosuite/faveo-invoicing-community) must send, for its developers. Everything new is signed with the same Ed25519 key as `license.json`. The existing endpoints and signatures stay as they are: `/api/licenseInstall`, `/api/licenseVerify`, `/api/ConnectionTest`, `/update-latest-version`, `/api/getVersions`, `/api/getAllVersions` and `/api/downloadFile`. The package doesn't use `/api/licenseScheme` (it creates its own `license_cache` table) or `/api/fetchQuery` (database changes are Laravel migrations).

## Identity file

The file Faveo Invoicing writes at the product's `config_file_path`. It can be `KEY=VALUE` lines (like `storage/faveoconfig.ini`) or JSON, and both use the **same key names**. Case doesn't matter, so `PRODUCT_ID` and `product_id` are the same key:

```ini
SERVER_URL=https://invoicing.example.com
PRODUCT_ID=5
PRODUCT_KEY=...
ED25519_PUBLIC_KEY=base64...
LICENSE_MODE=FILE
APP_VERSION=v9.2.0
```

```json
{"SERVER_URL": "https://invoicing.example.com", "PRODUCT_ID": 5, "PRODUCT_KEY": "...", "ED25519_PUBLIC_KEY": "base64...", "LICENSE_MODE": "FILE", "APP_VERSION": "v9.2.0"}
```

| Key | Sets |
|---|---|
| `SERVER_URL` | `server_url` |
| `PRODUCT_ID` | `product_id` |
| `PRODUCT_KEY` | `updates.product_key` |
| `ED25519_PUBLIC_KEY` | `public_key` |
| `LICENSE_MODE` | `mode`: `FILE` for File-mode orders, else `DATABASE` |
| `APP_VERSION` | `updates.current_version`: the version in this download, used to refuse downgrades (optional) |

- Other keys (`APP_NAME`, `APL_SALT`...) are ignored. `APL_SALT` is never read.
- **Plugin products:** the same file, at the plugin's own `config_file_path` inside its folder, plus `ADDON=calendar` (the plugin's name) and `LICENSE_FILE=<the plugin's license_file_path>`.
- **Plugin update files** use the customer's **main** licence code as `license_code`.
- **Never put the salt in this file.** It would be the same for every customer, and it's readable by anyone who downloads the product.

## Activation code (offline)

The text the customer pastes on the "Activate offline" page: `FLC1.` followed by the base64url encoding (no padding) of:

```json
{"v": 1, "product_id": 7, "license_code": "...", "domain": "app.test", "machine_id": "<hashed>", "addon": null}
```

- `domain` has no scheme and no `www.`.
- `machine_id` is the hashed ID, scoped to the **main** product, so add-ons send the same value.
- `addon` is the add-on name, or null.

Faveo Invoicing should:
1. Check the licence code belongs to `product_id`.
2. Bind `domain` and `machine_id`. If the licence is already bound to a different machine, refuse, or count it as a server move within the allowed limit.
3. Return the signed `license.json` for download.

## Offline update file

A zip, downloaded from My Orders, containing:

| File | Content |
|---|---|
| `update.zip` | The normal stamped update, stored uncompressed |
| `manifest.json` | `{"v": 1, "product_id": 5, "version": "v9.2.0", "released_at": "2026-10-01", "license_code": "...", "sha256": "<hex sha256 of update.zip>"}` |
| `manifest.sig` | base64 Ed25519 over the exact bytes of `manifest.json` |

Build it only when the licence is active and `released_at` falls within the licence's update period.

## Entitlements

**In `license.json`** (offline): inside the signed `license` payload:

```json
"entitlements": {
  "features": ["sla", "reports"],
  "limits":   {"agents": 25, "departments": null},
  "expires":  {"license": "2027-01-01", "updates": "2026-12-01", "support": "2026-12-01"}
}
```

**In the `licenseInstall` / `licenseVerify` replies** (online): two keys in `notification_data`:
- `entitlements`: the block above as a JSON **string**, including `"issued_at": "<ISO-8601 UTC now>"`.
- `entitlements_signature`: base64 Ed25519 over `"{product_id}|{license_code}|{root_url}|{entitlements}"`, using the request's exact strings.

Blocks issued more than 24 hours from the client's clock are refused, which stops replays.

## Online update archives

Two headers on the `/api/downloadFile` reply:
- `archive_sha256`: hex sha256 of the zip.
- `archive_signature`: base64 Ed25519 over `"{product_id}|{version_number}|{archive_sha256}"`.

Until Faveo Invoicing sends these, online clients need `updates.require_signed_archives = false`. Set that only temporarily.

## Licensed add-ons

`POST /api/licensedPlugins`, signed like `/api/getAllVersions` (the same fields, plus `license_code`). Faveo Invoicing:
- checks `license_code` is an active licence of `product_id`,
- finds the customer from that licence's order (never from anything else the client sends),
- replies `notification_operation_ok` with `{"plugins": [...]}`: one row per installable add-on with `product_id`, `product_name`, `product_key`, `product_description`, `version`, `license_code`, `path` (its install folder) and `dependency`,
- or `notification_license_not_found`.

