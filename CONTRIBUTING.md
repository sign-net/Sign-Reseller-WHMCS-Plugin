# Contributing to Sign.net Reseller for WHMCS

Thank you for helping. This guide covers setting up, the checks every change must pass, the rules the
code follows, and how to send a change.

The plugin has two parts: a provisioning module (one WHMCS service is one Sign.net private-label
portal) and an addon (packages, add-ons, allowance and a list of what needs attention). It calls
Sign.net's reseller console API, `/console/{slug}/reseller/*`, with an access token minted from the
reseller's API key. The user-facing docs are [README.md](README.md) and [docs/INSTALL.md](docs/INSTALL.md);
[docs/TESTING.md](docs/TESTING.md) is the manual test run in a real WHMCS.

## This repository is public

Issues, pull requests, commits and code are visible to everyone. Never put any of these in them:

- an API key, access token, password, or a dump of WHMCS's `$params`;
- a customer's name, email address or portal address;
- details of Sign.net's systems beyond what this repository's own docs say.

The module log masks keys and tokens, but read an excerpt before you paste it. To report a
vulnerability, follow [SECURITY.md](SECURITY.md) instead of opening an issue.

## Setting up

You need PHP 8.1 or later, Composer 2, and the `pdo_sqlite` extension for the tests.

```bash
composer install
```

Composer installs development tools only: the plugin itself runs without it. To try the plugin in a
real WHMCS, follow [docs/INSTALL.md](docs/INSTALL.md).

## Checks

Every change must pass these. CI runs them on PHP 8.1, 8.2 and 8.3:

```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G   # level 8 + strict rules, no baseline
vendor/bin/phpcs                               # PSR-12; warnings (120-column lines) fail too
find modules tests -name '*.php' -print0 | xargs -0 -n1 php -l
composer validate --strict
```

CI also checks that the API client never references WHMCS, and that no real Sign.net API key is
committed.

## Layout

| Path | What lives there |
|---|---|
| `modules/servers/signnet/signnet.php` | The `signnet_*` functions WHMCS calls. Thin: each hands over to `Module\ServerModule`. |
| `modules/addons/signnet_reseller/` | The addon's `signnet_reseller_*` functions and `hooks.php`. Thin too. |
| `modules/servers/signnet/lib/ResellerApi/` | `SignNet\ResellerApi`: the API client. **Never references WHMCS** (CI greps for it), because it is meant to become a standalone package. |
| `modules/servers/signnet/lib/Whmcs/` | `SignNet\Whmcs`: `Db/` (the plugin's tables and WHMCS's `tblhosting`), `Support/` (settings, logging, error text, HTML), `Provisioning/` (create, plan, lifecycle), `Module/` (what the entry functions do), `Admin/`, `Catalogue/` and `Hooks/` (the addon). |
| `modules/servers/signnet/templates/` | Client-area Smarty templates. |
| `tests/` | PHPUnit. `tests/stubs/` fakes WHMCS; `tests/Support/` holds the base test cases and canned Sign.net answers. |

## Rules

- **PHP 8.1 is the floor** (WHMCS 8.13 LTS), and the code must also run on WHMCS 9.0: no readonly
  classes, `true`/DNF types or typed class constants. PHPStan enforces `phpVersion: 80100`.
- **No Composer at runtime.** WHMCS ships its own vendor directory, so `lib/autoload.php` loads the
  plugin's classes. Dev tools only in `composer.json`.
- **Entry points never let an exception reach WHMCS.** Module functions answer `"success"` or a
  message for the administrator (`Support\ErrorText::describe()` for API failures).
- **Never log or show a secret.** Calls reach the module log only through `LoggingTransport`, which
  masks the key, tokens and confirmation keys. Never log `$params`: it holds `serverpassword`.
- **Creating a portal must stay idempotent.** `ProvisionService` records an attempt before calling
  Sign.net and, after a lost answer, looks for the portal before trying again. A hostname Sign.net
  says is taken is adopted only when this service's own unanswered attempt explains it. Keep both.
- **Reseller calls go through `ConsoleSender`**, which puts the reseller's slug (its primary host) in
  the path. A key learns the slug only from `GET /console/dashboard`, so it is learnt once per access
  token and stored with it, and a 403 `FORBIDDEN` learns it again once in case the host was renamed.
- **Orders spend Sign.net's rate limits.** Its billing routes allow 60 calls a minute per IP, its
  portal routes another 60, and allocations (new portals, assignments, add-ons) 20 a minute per
  reseller, shared by all its keys. A new portal's order reads only `billing/quota`, its package and
  the add-ons it orders (the list once per run, through `AddonCatalogue`), and trusts the package
  outcome in the provisioning answer rather than reading the assignment back. Every read added there
  lowers how many orders a minute WHMCS can take.
- **ConfigOptions are positional.** WHMCS stores them as `configoption1..N`: only ever append.
- **The plugin's tables are never dropped.** `Db\Schema::install()` is idempotent and only adds.
- **Read query rows through `Db\Rows`**; PHPStan rejects property access on the untyped objects the
  query builder returns.
- **Codes are not safe array keys.** PHP turns a numeric string key (`"100"`) into an int, and
  Sign.net codes may be numeric. Type code-keyed maps `array<array-key, int>` and read keys back
  with `(string)`, or prefix the key (`AddonCatalogue` does).
- **Escape all HTML output** with `Support\Html::escape()`. Only checkout errors use
  `Hooks\CustomerHtml`, because WHMCS has already encoded what customers type there; the plugin's
  own rows hold plain text. Admin POSTs check `check_token('WHMCS.admin.default')`; client-area POSTs
  carry the CSRF token.
- **Keep it small and plain.** One responsibility per class, functions short enough to read without
  scrolling, names that say what a thing is for, no dead code, and comments only for a why the code
  cannot show.

## Tests

- Every change needs tests. Glue tests run against an in-memory SQLite database standing in for
  WHMCS's (`DatabaseTestCase`), with a fake Sign.net behind every client the plugin builds
  (`ModuleTestCase`, `SignNetResponses`, the SDK's `FakeTransport`). `WhmcsFake` records what the
  plugin told WHMCS (`logModuleCall`, `logActivity`, `localAPI`) and answers `localAPI`.
- `tests/bootstrap.php` defines the `WHMCS` constant, so tests can `require_once` entry files.
- Test keys use the key id `0123456789abcdef0123456789abcdef`. CI fails on a key with any other id.
- `docs/TESTING.md` quotes the plugin's screens and messages exactly. When one changes, update its case
  there too, or testers check against stale expectations.

## Commits and pull requests

- Branch from `main`, and open your pull request against `main`.
- One logical change per commit. A commit message is a summary in the imperative ("Add", not "Added"),
  then a **Why:** paragraph (the problem) and a **What:** paragraph (the change, not the file list).
- Say in the pull request how you checked the change. Try anything an administrator or a customer sees
  in a real WHMCS.
- Contributions are accepted under the project's [MIT licence](LICENSE).
