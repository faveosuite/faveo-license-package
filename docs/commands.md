# Commands

All commands that act on a plugin take `--addon=<name>`, the name from the `addons` config or the plugin's identity file.

| Command | What it does |
|---|---|
| `license:install {code} [--email=] [--addon=] [--url=]` | Activate. Online: asks Faveo Invoicing. Offline: uses the licence file Faveo Invoicing already stamped into the download |
| `license:request {code} [--addon=] [--url=]` | Offline: print the activation code for Faveo Invoicing's "Activate offline" page |
| `license:import {file} [--addon=] [--url=]` | Offline: install a licence file from Faveo Invoicing (activation, renewal, new server) |
| `license:status [--addon=] [--force]` | Show the state, where the identity values came from, entitlements and expiry dates. `--force` does a full check instead of reading the cache |
| `license:update {version} [--addon=] [--no-migrate]` | Online: download and install a version of the product or a plugin |
| `license:update --file= [--force] [--no-migrate]` | Offline: install an update file, for the product or a plugin (recognised automatically). `--force` allows an older version |
| `license:update-check` | Online: list versions from Faveo Invoicing and the update period |
| `license:rollback [--addon=]` | Restore the files from the most recent update backup of the product or a plugin |
| `license:uninstall [--addon=]` | Remove the licence from this installation (the licence file, if any, stays). A tampered licence doesn't need this: `license:install` replaces it |
| `license:import-legacy [--table=] [--plugin-table=]` | Online: activate the licences left by the old licence scripts. Runs once on `migrate`; this retries it |

`--url=` sets this installation's URL when it differs from `APP_URL`. It must match the licensed domain.

`license:update` needs either a version or `--file=`, never both.
