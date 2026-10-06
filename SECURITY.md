# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.1.x | Yes |
| Earlier | No |

## Reporting a vulnerability

Please don't open a public issue. Report it privately through
**[Report a vulnerability](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/security/advisories/new)**
on this repository's Security tab.

Include:

- what an attacker could do, and what they need first (a WHMCS admin login, a client account, or
  nothing);
- how to reproduce it, with the plugin, WHMCS and PHP versions;
- any module log excerpts, with keys, tokens and customer details removed.

We'll confirm we have it, keep you told of progress, and, if you'd like, credit you in the published
advisory.

A problem in Sign.net's service rather than in this plugin can be reported the same way, and we'll
pass it on.

## If an API key leaks

Revoke it straight away in your Sign.net reseller console (**API → Keys**), create a new key, and put
it in the WHMCS server's Password field.
