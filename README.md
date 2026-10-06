# Sign.net Reseller for WHMCS

[![CI](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/sign-net/Sign-Reseller-WHMCS-Plugin)](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/releases/latest)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)
![WHMCS 8.13 | 9.0](https://img.shields.io/badge/WHMCS-8.13%20%7C%209.0-0b6e4f.svg)

Sell Sign.net private-label signing portals from WHMCS. Each WHMCS service is one portal, on the
customer's own hostname, holding one of your Sign.net packages. WHMCS creates, suspends and
deletes it, and your customers see it on their service page.

It has two parts:

| Part | Folder | What it does |
|---|---|---|
| **Provisioning module** "Sign.net Private Label" | `modules/servers/signnet/` | Creates the portal when an order is paid, suspends and unsuspends it with the service, applies package and add-on changes, deletes it on termination, and shows it on the admin and client service pages. |
| **Addon** "Sign.net Reseller" | `modules/addons/signnet_reseller/` | Your Sign.net packages and add-ons (create, edit, archive, and turn them into WHMCS products and configurable options), your allowance, and a list of services that need attention. Also sets the default branding new portals start with. |

## Features

- **Hands-off provisioning.** A paid order creates the portal with its owner, package and add-ons, and
  the owner gets Sign.net's set-password email.
- **Safe to retry.** A retried Create finishes what an earlier attempt started, and never makes a second
  portal, even when Sign.net's answer was lost.
- **Orders over your allowance wait for you.** Nothing is created in Sign.net until you approve one, unless
  the product is set to confirm automatically.
- **The portal follows the service.** Suspend and unsuspend, upgrades, downgrades and add-on changes, and
  deletion on termination.
- **Your catalogue, managed in WHMCS.** Create, edit and archive Sign.net packages and add-ons, and turn
  them into WHMCS products and configurable options.
- **A client area that explains itself.** Customers see their portal's state, the DNS records to create,
  each with a Copy button, and what their package includes.
- **A dashboard for you.** Your Sign.net plan and allowance, and a list of services that need attention.
- **Secrets stay secret.** WHMCS stores the API key encrypted, and the module log masks keys and tokens.

## Installation

Download `signnet-whmcs-plugin-<version>.zip` from the
[latest release](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/releases/latest), and copy the
`modules` folder inside it into your WHMCS root. Then follow **[docs/INSTALL.md](docs/INSTALL.md)** to
activate the addon, connect your Sign.net account and sell your first portal. It also covers day-to-day
use.

To test the plugin in a real WHMCS before you rely on it, follow **[docs/TESTING.md](docs/TESTING.md)**.
What changed in each version is in [CHANGELOG.md](CHANGELOG.md).

## Requirements

- WHMCS 8.13 LTS (PHP 8.1–8.3) or WHMCS 9.0 (PHP 8.2+).
- PHP extensions `curl` and `json`. `intl` is optional; it converts internationalised portal
  addresses (`bücher.example`) to their `xn--` form.
- A Sign.net reseller account on a Sign.net plan, and an API key (`snk_live_…`) with the scopes
  `reseller:read`, `reseller:provision` and `reseller:packages`.

## How a service maps to a portal

| WHMCS | Sign.net |
|---|---|
| Order paid (auto-setup) or **Create** | Portal created on the "Portal address" custom field (or the domain), with its owner (the client), the product's package and the add-ons the customer chose. The owner gets Sign.net's set-password email. |
| **Suspend** / **Unsuspend** | Portal suspended with WHMCS's reason (you and Sign.net see it; the portal's own people never do), or unsuspended. A suspension Sign.net made can only be lifted by Sign.net. |
| **Change Package** (upgrade, downgrade, configurable options) | Package swapped and add-on quantities changed to match. |
| **Terminate** | Portal deleted: you ask for it, or the cron does when the product allows it. This cannot be undone, and the hostname can never be used again. |

Creating is safe to repeat: a retried Create finishes what an earlier attempt started, and never
makes a second portal. An order that would take you past your Sign.net allowance waits for your
approval, and nothing is created in Sign.net until then.

## Known limitations

These come from Sign.net's current API:

- A hostname that was ever used, even by a deleted portal or another reseller, can never be used
  again. Checkout can only check the hostnames your own WHMCS knows about.
- Swapping a portal's package is not atomic: for a moment it holds none, and the credit it carried
  is written off. If the new package can't be assigned, the old one and its add-ons are put back,
  but the credit stays written off.
- Sign.net allows five token requests per fifteen minutes per IP address, whichever keys they are
  for. The plugin shares each key's token across every WHMCS process, and once Sign.net refuses a
  key it waits five minutes before asking about that key at that address again, Test Connection
  included. So this only matters when many different keys are tried.
- Sign.net takes about 20 new portals a minute per reseller account, shared by all its API keys,
  and fewer when orders include add-ons, because each add-on is a write of its own. An order past
  that fails with "Sign.net is rate limiting these requests; try again in <n> seconds." Retry it
  from WHMCS's module queue: a retry never creates a second portal.
- There is no sandbox. A key's prefix names the Sign.net deployment that minted it: `snk_live_` on
  production, `snk_test_` on any other, such as staging. A key works only there, and the portals it
  creates there are real.

## Development

```bash
composer install          # dev tools only; the plugin itself needs no Composer at runtime
vendor/bin/phpunit        # unit tests; WHMCS is faked with SQLite and recording stubs
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/phpcs          # PSR-12
```

CI runs these and the rest of the checks in [CONTRIBUTING.md](CONTRIBUTING.md) on PHP 8.1, 8.2 and 8.3.

| Path | Namespace | |
|---|---|---|
| `modules/servers/signnet/lib/ResellerApi/` | `SignNet\ResellerApi` | The Sign.net Reseller API client. Knows nothing about WHMCS, so it can become a standalone package. |
| `modules/servers/signnet/lib/Whmcs/` | `SignNet\Whmcs` | Everything WHMCS: the module, the addon pages, hooks and the plugin's tables. |
| `modules/servers/signnet/lib/autoload.php` | | Loads both, since WHMCS ships its own Composer vendor directory. |
| `tests/` | `SignNet\Tests` | PHPUnit tests, with WHMCS stubs in `tests/stubs/`. |

See [CONTRIBUTING.md](CONTRIBUTING.md) for the conventions this code follows and how to send a change.

## Security

Please report vulnerabilities privately, as [SECURITY.md](SECURITY.md) describes, never in a public issue.

## License

MIT: see [LICENSE](LICENSE).

WHMCS is a trademark of WHMCS Limited. This project is not affiliated with or endorsed by WHMCS Limited.
