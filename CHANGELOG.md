# Changelog

Notable changes to the plugin, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.1.0 - 2026-10-06

The first public release.

### Added

- Packages and add-ons can include notarisations.
- The addon's dashboard shows what is used outside packages: your own team's use, and what portals
  used beyond their packages.
- Each release comes with a ready-to-install zip.

### Changed

- The plugin calls Sign.net's reseller console API (`/console/{slug}/reseller/*`), which replaced the
  `/api/v1/reseller/*` API that 1.0 called. Upgrading adds one column to the plugin's token table, and
  existing links between services and portals keep working.
- Access tokens, and the pause after Sign.net refuses a key, are kept per Sign.net address as well as
  per key, so a corrected hostname is tried straight away.
- Test Connection checks the key's scopes before calling Sign.net, and waits out a refused key's
  five-minute pause instead of spending a token request on every click.
- After a lost answer, a portal is adopted only when Sign.net says its hostname is taken.
- Sign.net's refusals are explained in words an administrator can act on.
- A new portal's order makes fewer reads on Sign.net's rate-limited billing endpoints.

### Fixed

- When a package swap fails, or is held over the allowance, the old package is put back with its
  add-ons.
- A swap to an archived package is refused before anything changes, so the portal keeps its carried
  credit.
- Saving a package or add-on in WHMCS keeps the items its form does not show.
- The welcome email shows a portal's name exactly as it was typed.
- The activity log no longer says a replaced add-on is billed twice.

## 1.0.0 - 2026-09-29

The first release, used only inside Sign.net: the provisioning module, the reseller addon, and the
checkout and welcome email hooks.
