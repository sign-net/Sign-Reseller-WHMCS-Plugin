# Changelog

Notable changes to the plugin, newest first. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- The dashboard lists a live portal whose WHMCS service is Cancelled, Terminated or Fraud, and the
  service page now flags one on a Fraud service too.

### Changed

- **Link existing portal** names the linked portal's owner in the activity log, so you can check
  that it is the client's.
- While Sign.net is rate limiting, failing or out of reach, the service page and the client area
  wait before asking about a portal again: a minute, or as long as Sign.net asks, up to an hour. A
  portal deleted outside WHMCS is looked for again after a day. **Refresh from Sign.net** still asks
  at once.
- A configurable option's maximum quantity must now be 1 or more, because WHMCS reads 0 as no
  limit. To stop selling an add-on, untick every product it is offered on.
- An add-on whose code holds anything but the letters A to Z, digits, hyphens and underscores can no
  longer be made into a configurable option, and the page says why. Options that already exist keep
  working.
- The module writes a portal's tenant id and address to its WHMCS service only when they are in the
  form Sign.net issues them. Otherwise the command says so and leaves the service's Username and
  Domain as they were.
- Upgrading adds two columns to the plugin's service table, and records the WHMCS order of each
  existing link's service.

### Fixed

- 1.1.0's notes said that after a lost answer a portal is adopted only when Sign.net says its
  hostname is taken, which was not what the plugin did. A portal is now adopted only when it fits
  the lost call: it is on the address the call asked for, it was created after the call was sent,
  and it is owned by the client's email address or has no owner yet. Any other portal on that
  address is reported as already in use.
- When getting an access token or the reseller's console address fails before a portal is
  requested, the attempt is recorded as failed, not in doubt, so the next Create requests the portal
  instead of looking for one.
- Changing a portal's address while a provisioning call is in doubt no longer leaves behind a portal
  that call made. Create first looks at the old address, links the portal there if it fits the
  call, and says the address was not changed. The in-doubt message now says to keep the address
  until Create has looked.
- A portal adopted after a lost answer has its DNS records in the welcome email, as a new portal
  does.
- An over-allowance approval covers only the run it starts. A run that failed or was held again used
  to keep it, and a later change of plan, such as an upgrade the customer paid for, was then
  confirmed past the allowance without a new approval.
- A portal already suspended outside WHMCS, in your Sign.net console or by Sign.net, keeps that
  suspension when WHMCS suspends the service. WHMCS's automatic unsuspension, such as after the
  client pays an overdue invoice, no longer lifts it. An administrator's **Unsuspend** lifts one
  made in your console, and so does a payment you record in the admin area. The dashboard lists
  such services as *Suspended outside WHMCS*.
- A new WHMCS service given the id of a deleted service, as MySQL 5.7 can after a restart, no longer
  takes over that service's live portal. Every action on it is refused with a message naming the
  portal until you click **Unlink (keeps the portal)**, and the dashboard lists the portal.
- A customer whose **Check again** failed was shown text written for administrators. They now read
  only “We could not check your portal's address right now. Please try again later.”, and the
  reason is in the activity log, in an entry starting
  `Sign.net: "Check again" on service #<id> failed:`.
- **Check again** runs at most once a minute per service, even when two requests arrive at the same
  moment, and a check that fails counts against the minute too.
- An add-on's name shows on WHMCS's order form and in the cart exactly as it was typed, ampersands,
  quotes and tags included. Upgrading fixes the names of the configurable options earlier versions
  made.

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
