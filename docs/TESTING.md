# Testing Sign.net Reseller for WHMCS

This is the plugin's manual test run. Section 1 sets up a test environment. Section 2 goes through every
feature in the order you meet it, saying what you should see at each step.

The automated tests (see [Development](../README.md#development)) run with WHMCS faked, so they cannot show
that the plugin works inside a real WHMCS. This run can. It also checks the WHMCS behaviours the plugin had to
assume: [What this run proves](#what-this-run-proves) lists them.

Run it on WHMCS 8.13 first. Then repeat the critical cases on WHMCS 9.0 ([J](#j-whmcs-90)).

## Before you start

- **Test on Sign.net staging, never production.** Sign.net has no sandbox: every portal this run creates is
  real. A key made in the staging console starts `snk_test_` and works only on staging; production's keys start
  `snk_live_`.
- **A portal address can be used once, ever.** Deleting a portal does not free its address, so each case
  below gives its portal a new one.
- **Package and add-on codes are permanent too**, and a reseller can hold 100 of each, archived ones
  included. Start this run's codes with its run number (`QA1-…`, and `QA2-…` next time). Use a reseller that
  exists only for testing.
- **Sign.net allows five token requests per IP address every 15 minutes.** The plugin keeps one token per
  key and renews it about hourly, so A2 to A4, which try three keys, use three of the five. If a message says
  “Sign.net is rate limiting these requests; try again in <n> seconds.” or “Token minting for this Sign.net API
  key is paused until …” where the case does not expect it, wait that long. A3 expects the second.
- **The service page's Sign.net fields can be up to a minute old.** After changing something outside WHMCS,
  click “Refresh from Sign.net”.
- **Every case has an id and a priority:**
  - **critical** cases are the smoke test;
  - **high** cases cover the rest of the plugin's main job;
  - **normal** cases cover limits and edge cases.
- **On-screen text is quoted exactly**, in curly quotes (“Test Connection”), so you can search the page for
  it.
  - `<id>`, `<date>` and similar stand for values that vary.
  - A module command's answer shows at the top of the service page.
  - When the plugin passes on a failed call, the message ends with “(request <id>)”, and the module log names
    the same call with the same id. The few messages that explain a refusal in their own words (F1, D3, G4)
    carry no id.

## 1. Set up the test environment

### What you need

| | |
|---|---|
| **WHMCS 8.13** | A licensed install you may test on. It needs PHP 8.1, 8.2 or 8.3 with the `curl` and `json` extensions, HTTPS access to the Sign.net staging API, and a working cron. |
| **WHMCS 9.0** | A second install on PHP 8.2 or later, for [J](#j-whmcs-90). |
| **A test reseller on Sign.net staging** | Ask Sign.net for one, set up as [On Sign.net staging](#on-signnet-staging) says, and for the staging API host. You use its reseller console and the portals this run creates. |
| **A domain you control** | For the test portals' addresses. Each run adds one DNS record, in C8. This guide writes `example.com`: use your own domain instead. |
| **Two mailboxes** | One for the test reseller's console owner. One for the WHMCS test client, who owns every test portal and so gets Sign.net's set-password emails. |

### Test data

Use these values, so the messages you see match this guide.

| What | Values |
|---|---|
| Your own packages | `QA1-START` “QA Starter”: Monthly, base price 19.00, Documents 100, Seats 5, Templates 3.<br>`QA1-PRO` “QA Pro”: Monthly, base price 49.00, Documents 500, Seats 20, Templates 10, Notarisations 20. |
| Add-on | `QA1-SEATS` “5 extra seats”: Monthly, 5.00 per unit, grants Seats 5. |
| Portal addresses | `p01.qa1-dns.example.com` for the first portal (C2). Then `p02.qa1.example.com` to `p09.qa1.example.com`, as each case says. Only p01 needs DNS. |

### On Sign.net staging

1. **Ask Sign.net for a test reseller.** It should exist only for testing, and have:
   - a Sign.net plan billed Monthly that includes Documents 2000, Seats 100, Templates 50 and Notarisations 100;
   - the primary host `reseller.qa1.example.com`, which needs no DNS for this run;
   - the first mailbox as its owner, able to sign in to the reseller console. Sign.net's set-password link
     opens on the primary host, so while that host has no DNS, ask Sign.net to set an initial password instead.
2. **Create the API keys.** Open the reseller console, sign in as the owner, and click “Keys” under “API” in the
   sidebar. The page is headed “API Keys”.
   - **The full key**: “Read (list private labels, usage)” is already ticked. Also tick “Provision
     (create/manage private labels)” and “Packages (author your catalogue, assign it to a label)”, then click
     “Create key”. Copy the key shown under “Your new secret — copy it now, it will not be shown again:”. It
     starts `snk_test_`.
   - **The read-only key**: untick “Provision (create/manage private labels)” and “Packages (author your
     catalogue, assign it to a label)”, so only “Read (list private labels, usage)” is ticked, and click
     “Create key” again. A4 uses this key.
3. On the console's Dashboard, note the “Package quota” card. B1 compares WHMCS's figures with it.

### In WHMCS

1. **Create a test client** (Clients → Add New Client) with a first name, a last name and the second
   mailbox's address.
2. **Create a product group** (System Settings → Products/Services → Create a New Group), for example
   *Sign.net portals*.
3. **Activate a payment gateway you can mark paid by hand**, such as Bank Transfer, so you can pay test
   orders from the admin area.
4. **Turn on module debug logging** (System Logs → Module Log → Enable Debug Logging). I1 reads the log.

## 2. The test run

### A. Install and connect

**A1 · critical · Activate the addon**

1. Copy `modules/servers/signnet/` and `modules/addons/signnet_reseller/` into the WHMCS root, keeping their
   paths.
2. Go to System Settings → Addon Modules → Sign.net Reseller → Activate.
3. Click Configure, give your admin role access, and save.
4. Open Addons → Sign.net Reseller.

- [ ] Activating shows “Sign.net Reseller is active. Add your Sign.net account as a server (System Settings >
  Servers, module Sign.net Private Label, the API host as Hostname and your snk_ key as Password), then open
  Addons > Sign.net Reseller.”
- [ ] System Settings → Email Templates lists “Sign.net Portal Welcome” among the product emails, with the
  subject “Your Sign.net portal is ready”.
- [ ] The addon shows the tabs “Dashboard”, “Packages” and “Add-ons”. Because no server exists yet, it says
  “Add a Sign.net server first (System Settings > Servers), with the API host as Hostname and the snk_ key as
  Password.”

**A2 · critical · Add the server and test the connection**

1. Go to System Settings → Servers → Add New Server. Fill it in as [INSTALL.md §3](INSTALL.md#3-add-the-signnet-server) says:
   - Hostname: the staging API host.
   - Module: “Sign.net Private Label”.
   - Password: the full key.
   - Secure: ticked.
2. Click “Test Connection”, then save.
3. Create a server group containing this server (System Settings → Servers → Create New Group).

- [ ] Test Connection succeeds.
- [ ] Addons → Sign.net Reseller now opens its Dashboard.

**A3 · high · A refused key**

1. In the server's Password field, change the key's last character to a different letter or digit, and click
   “Test Connection”.
2. Click “Test Connection” again.
3. Replace the whole key with `hello` and test again.
4. Put the full key back, test again, and save.

- [ ] Step 1 says “Sign.net refused the API key. Check that the server's Password field holds a current snk_
  key. (request <id>)”
- [ ] Step 2 says “Token minting for this Sign.net API key is paused until <date> <time> UTC because the key was
  refused (invalid_client). A corrected key or hostname is tried straight away.”, and the module log has no
  new “POST /api/v1/auth/token” entry for it.
- [ ] Step 3 says “The Sign.net API key is malformed: expected "snk_live_" or "snk_test_", a 32-character key
  id and the secret, as shown when the key was created.”
- [ ] Step 4 succeeds. A refused key pauses only its own token minting, so the full key is not held up.

**A4 · high · A key without every scope**

1. Put the read-only key in the Password field, click “Test Connection”, and save. The addon's pages use the
   saved key, not what the form holds.
2. Open Addons → Sign.net Reseller.
3. Put the full key back, test it, and save. Every later case needs it.

- [ ] Test Connection says “The API key lacks reseller:provision, reseller:packages. Create a key with
  reseller:read, reseller:provision and reseller:packages.”
- [ ] Under “API key”, the Dashboard marks `reseller:provision` and `reseller:packages` “Missing”, and says
  “The key lacks reseller:provision, reseller:packages. Create a key with all three scopes in your Sign.net
  console and put it in the server's Password field.”

**A5 · normal · Portal defaults**

1. Go to System Settings → Addon Modules → Sign.net Reseller → Configure. Set “Primary colour” to `blue` and
   save, then open the addon's Dashboard.
2. Set “Primary colour” to `#0b6e4f`, save, and open the Dashboard again. Leave the colour set: C9 checks
   that the first portal uses it.

- [ ] After step 1, “Needs attention” lists “Addon setting "Primary colour" is not valid”, with “It must be a
  colour (#rrggbb). New portals ignore it until it is fixed under System Settings > Addon Modules.”
- [ ] After step 2, the entry is gone.

**A6 · normal · Upgrading from 1.0**

Only on a WHMCS where version 1.0 of the plugin was installed and linked to at least one portal. Version 1.0
calls the API Sign.net has retired, so on staging its calls now fail.

1. Copy this version's `modules/servers/signnet/` and `modules/addons/signnet_reseller/` over the old ones.
2. Open Addons → Sign.net Reseller.
3. Open a service linked under 1.0, and click “Refresh from Sign.net”.

- [ ] The Dashboard opens and shows “Plan and allowance”.
- [ ] The service still shows its portal, with its status read from Sign.net.
- [ ] In the module log, the first call after a new token is “GET /console/dashboard [<id>]”, and the calls
  after it name paths under “/console/<your reseller's host>/reseller/”. No entry after the upgrade names
  “/api/v1/reseller/”.

### B. Packages and add-ons

**B1 · critical · The dashboard**

Open Addons → Sign.net Reseller → Dashboard.

- [ ] “Plan and allowance” shows “Plan: <your plan's name> (Monthly).”, then the billing window.
- [ ] A table lists Documents, Seats, Templates and Notarisations under “Included”, “Allocated now”, “Used
  outside packages”, “Remaining” and “Used”.
  The figures match the console's “Package quota” card, which calls Documents “Documents sent”. “Used outside
  packages” is the card's “Also taken from your allowance” figures added together, or 0 where it shows none.
- [ ] “API key” shows “Environment: Test”, since a staging key starts `snk_test_`, and all three scopes read
  “Granted”.
- [ ] “Needs attention” says “Nothing needs your attention.”

**B2 · critical · Create packages**

1. Go to Packages → “New package”, and fill in:
   - Code: `qa1-start`, typed in lower case on purpose.
   - Name: “QA Starter”.
   - Your WHMCS currency, and Billing cycle “Monthly”.
   - Base price: `19.00`.
   - Included: Documents 100, Seats 5, Templates 3. Leave Notarisations blank.
2. Click “Create package”.
3. Create `QA1-PRO` the same way, with the values from [Test data](#test-data).

- [ ] Below the items, the form says “Leave Included blank to leave an item out. A portal given no
  notarisations notarises from your own pool instead, and you are billed what it uses.”
- [ ] Each shows “Package QA1-START was created in Sign.net.” (or QA1-PRO).
- [ ] The list shows the codes in upper case, “Monthly”, the base price with its currency, and “Active”.
- [ ] Above the list, “<n> of 100 packages used, <m> of them archived. Archived packages still count, and
  their codes can never be used again.” counts them.
- [ ] Both packages appear on the console's Packages page.

**B3 · high · A code can never be reused**

Create another package with code `QA1-START`.

- [ ] It is refused: “That code is taken by one of your packages or add-ons, archived ones included; a code can
  never be reused. (request <id>)”

**B4 · high · Edit a package**

1. On QA1-START, click “Edit”, change the Description, and click “Save changes”.
2. Click “Edit” again, and click “Save changes” without changing anything.

- [ ] The edit form says “Code QA1-START, priced in <currency>, billed monthly. These cannot change.”
- [ ] Step 1 says “Package QA1-START was saved.”
- [ ] Step 2 says “Nothing was changed.”

**B5 · normal · Archive and activate**

On QA1-PRO, click “Archive”, then “Activate”.

- [ ] Archiving says “The package is archived. It still counts towards your 100, and its code stays taken.”
  The status reads “Archived”.
- [ ] Activating says “The package is active again.”

**B6 · critical · Create a WHMCS product from a package**

1. On QA1-START, click “WHMCS product”. The page is headed “Sell QA Starter (QA1-START) in WHMCS”.
2. Under “Create a product”, set:
   - Product group: yours.
   - Product name: keep “QA Starter”.
   - Server group: the group from A2.
   - “Orders that would go over your Sign.net allowance”: “Hold them for approval”.
   - “Automated termination”: “Only after the client asks to cancel”.
3. Click “Create product”, then “Open the product”.

- [ ] It says “WHMCS product #<id> was created to sell package QA1-START.”
- [ ] Module Settings shows the module “Sign.net Private Label” with these settings:
  - “Sign.net package” lists your active packages, such as “QA Starter (QA1-START)”, and QA Starter is
    selected.
  - “Orders over the allowance” reads “Hold for approval”.
  - “Automated termination” reads “Only after a cancellation request”.
- [ ] Pricing offers only Monthly, at 19.00, with no setup fee. Every other cycle is disabled.
- [ ] Custom Fields lists:
  - “Portal address”: required, shown on the order form, described “Where your portal will live, e.g.
    sign.example.com”.
  - “Portal name”: optional.
- [ ] The welcome email is “Sign.net Portal Welcome”, and the product is set up automatically when payment is
  received.

**B7 · high · Link an existing product**

1. Create a product by hand in your product group, named “QA Pro”, with no module and a Monthly price of 49.00.
2. On QA1-PRO, click “WHMCS product”. Under “Or link an existing product”, choose “QA Pro (#<id>)” and
   click “Link product”.
3. Open the product. Set its server group to the one from A2 (linking leaves this alone), and save.

- [ ] It says “WHMCS product #<id> now sells package QA1-PRO through Sign.net.”
- [ ] The product now uses “Sign.net Private Label” with “QA Pro (QA1-PRO)” selected. It has gained the
  “Portal address” and “Portal name” fields, and its price is unchanged.

**B8 · critical · Create an add-on**

1. Go to Add-ons → “New add-on”, and fill in:
   - Code: `qa1-seats`.
   - Name: “5 extra seats”.
   - Billing cycle: “Monthly”.
   - Price per unit: `5.00`.
   - Granted per unit: Seats 5.
2. Click “Create add-on”.

- [ ] It says “Add-on QA1-SEATS was created in Sign.net.”, and the list shows “5.00 <currency>” and
  “Active”.

**B9 · critical · Sell the add-on as a configurable option**

1. On QA1-SEATS, click “Configurable option”.
2. Set “Maximum quantity” to 10, tick both QA Starter and QA Pro under “Offer it on”, and click “Create
   configurable option”.
3. Click “Open the option group”.
4. Back on the addon, click “Configurable option” on QA1-SEATS again.

- [ ] Before you save, the page says “Creates the option group "Sign.net add-on: 5 extra seats" with a Quantity
  option named "addon_QA1-SEATS|5 extra seats", priced at 5.00 <currency> per unit, monthly, and the same per
  month on products billed on other cycles.”
- [ ] Saving says “The configurable option is saved in group "Sign.net add-on: 5 extra seats" and offered on 2
  product(s).”
- [ ] In WHMCS, the group is assigned to both products. Its option “addon_QA1-SEATS|5 extra seats” has these
  settings:
  - type Quantity;
  - maximum 10;
  - price 5.00 monthly, 15.00 quarterly, 30.00 semi-annually, 60.00 annually, 120.00 biennially and 180.00
    triennially, with no setup fees.
- [ ] Opening the page again says “This add-on is sold through option group "Sign.net add-on: 5 extra seats".”,
  and its button reads “Save”.

### C. The first order

**C1 · high · Checkout checks the portal address**

1. As the test client, open the store and add QA Starter with Portal address `not a host`. Go to checkout
   and complete the order.
2. Empty the cart. Add QA Starter twice, both times with Portal address `p08.qa1.example.com`, and complete
   the order.
3. Empty the cart.

- [ ] Step 1 is refused: “QA Starter: "not a host" is not a valid portal address. Use a hostname such as
  sign.example.com: letters, digits and hyphens, with at least one dot.”
- [ ] Step 2 is refused: “p08.qa1.example.com is in your cart more than once. Each portal needs its own
  address.”

**C2 · critical · Order and pay**

1. As the test client, order QA Starter with these values:
   - Portal address: `https://P01.qa1-dns.example.com/`. The capitals and `https://` are on purpose.
   - Portal name: `Tom & Jerry's "QA" <1> &amp;`. The `&amp;` at the end is typed literally, on purpose: it
     shows whether WHMCS encodes what customers type (C5, C6).
   - 5 extra seats: 1.
2. Complete the order with the manual gateway.
3. In the admin area, open the order's invoice and add the payment. If your WHMCS accepts orders by hand,
   accept the order too. Paying is what sets the service up: that is the path this case tests, and the one that
   sends the welcome email C5 checks.

- [ ] The service is Active. Its Domain is `p01.qa1-dns.example.com`, and its Username is a 32-character
  tenant id.
- [ ] The activity log (System Logs → Activity Log) has “Sign.net: portal p01.qa1-dns.example.com created for
  service #<id>.”

**C3 · critical · The service page**

Open the service in the admin area.

- [ ] “Sign.net portal” links to the address and shows “tenant <id>”.
- [ ] “Portal status” reads “Active”.
- [ ] “Owner” shows the client's name and email address.
- [ ] “Package” reads “QA Starter (QA1-START)”, then “Add-ons: QA1-SEATS × 1”, then an “Allocated:” line.
- [ ] “Portal users” counts the portal's seats. For now that is 1: the owner.
- [ ] “Domain” reads “DNS pending”, with a Type / Name / Value table. The table is usually one CNAME record for
  p01.qa1-dns.example.com.
- [ ] There is no “Needs attention” row.

**C4 · high · Resending the owner's invite**

1. Within five minutes of C2, click “Resend owner invite”.
2. After five minutes, click it again.

- [ ] Step 1 is refused: “Sign.net sends a person at most one set-password email every five minutes, counting
  the one sent when the portal was created. Try again later. (request <id>)”
- [ ] Step 2 succeeds, and the client's mailbox gets another set-password email.
- [ ] The activity log has “Sign.net: set-password email resent to the owner of p01.qa1-dns.example.com.”

**C5 · critical · The emails**

Check the test client's mailbox, or the client's Emails tab in WHMCS.

- [ ] WHMCS's “Your Sign.net portal is ready” names the portal `Tom & Jerry's "QA" <1> &amp;` exactly. The
  first `&` is a plain ampersand, and the typed `&amp;` is still `&amp;`, not `&`. It also gives:
  - the address https://p01.qa1-dns.example.com;
  - the DNS record to create;
  - that the owner's link expires after 24 hours.
- [ ] Sign.net's own email with the set-password link has arrived too.

**C6 · high · What Sign.net shows**

In the reseller console, click “Accounts” under “Private Label”.

- [ ] The console lists p01.qa1-dns.example.com with the client's email address and QA Starter.

**C7 · critical · The client area before DNS**

As the test client, open the service.

- [ ] The Overview is headed `Tom & Jerry's "QA" <1> &amp;` and shows Status “DNS pending”. The address shows
  as plain text, not a link.
- [ ] “Point your domain at your portal” lists the record. Its “Copy” button changes to “Copied”.
- [ ] “Your package: QA Starter” lists “Documents: 100”, “Seats: 10” and “Templates: 3”, with no Notarisations line
  (QA Starter gives none, so the portal notarises from your pool), and under “Add-ons:” shows “5 extra seats × 1”.
- [ ] There is no “Open portal” button.
- [ ] Clicking “Check again” twice within a minute gives “You can check again in a minute.” the second time.

**C8 · critical · Going live**

1. Create the DNS record the service page shows. If it lists more than one CNAME for the same name, they are
   alternatives: create only the first.
2. When it resolves, click “Check again” as the client. The browser may warn about the certificate for a few
   minutes while one is issued.

- [ ] The Status is “Active”. The address is a link, and “Open portal” and “Theme and people” appear.
- [ ] The DNS table is gone.
- [ ] In the admin area, “Domain” reads “Live”.

**C9 · critical · The owner signs in**

1. Open the set-password link from Sign.net's email and set a password.
2. Sign in at https://p01.qa1-dns.example.com.

- [ ] The portal opens, using the primary colour from A5.
- [ ] The browser tab's title ends with `Tom & Jerry's "QA" <1> &amp;`, exactly as typed: the portal takes it from
  the name Sign.net holds.
- [ ] “Theme and people” in the client area opens the portal's Organisation page.

**C10 · high · Create is safe to repeat**

On the active service, run Module Commands → “Create”.

- [ ] It succeeds, and nothing changes: the console still lists one portal on p01.
- [ ] The activity log has no second “created” entry.

**C11 · high · An address is used only once**

As the test client, order QA Starter again with Portal address `p01.qa1-dns.example.com`.

- [ ] Checkout refuses it: “p01.qa1-dns.example.com is already in use. Choose another portal address.”

### D. Suspension

All on the p01 service, with the owner signed in to the portal from C9.

**D1 · critical · Suspend**

Run Module Commands → “Suspend”, with the reason `QA suspension test`.

- [ ] “Portal status” reads “Suspended” with “by you since <date>. Reason: QA suspension test”.
- [ ] The client area shows “Suspended” and “Your portal is suspended, so nobody can sign in to it. Please
  contact us.” It has no “Open portal” button.
- [ ] On the portal, the owner has been signed out:
  - signing in with the right password says “This portal is suspended. Please contact your provider.”;
  - a wrong password says “Invalid email or password”.
- [ ] The console's Accounts list shows “Suspended by you” beside the address. Opening the row shows, under
  “Suspension”, “Since <date> · Reason: QA suspension test”.

**D2 · critical · Unsuspend**

Run Module Commands → “Unsuspend”.

- [ ] “Portal status” reads “Active”, the owner can sign in again, and the client area shows “Active” with
  its links.

**D3 · high · Sign.net suspends the portal**

D3 and D4 need Sign.net to act on staging while you test, so arrange a time with them first.

1. Ask Sign.net to suspend p01, with the reason `QA platform test`.
2. In WHMCS, the service is still Active. Click “Refresh from Sign.net”.
3. Run “Suspend”, then “Unsuspend”.

- [ ] After step 2:
  - “Portal status” reads “Suspended”, with “by Sign.net, and only Sign.net can lift it since <date>. Reason:
    QA platform test”;
  - “Needs attention” says “WHMCS shows this service Active, but the portal is suspended.”;
  - the addon's Dashboard lists “Suspended in Sign.net, Active in WHMCS”.
- [ ] In step 3, Suspend succeeds, and the console still says “Suspended by sign.net”: your suspension never
  takes over Sign.net's.
- [ ] Unsuspend is refused: “Suspended by Sign.net: QA platform test. Only Sign.net can lift this suspension;
  contact Sign.net support.”

**D4 · high · Sign.net lifts it**

1. Ask Sign.net to lift p01's suspension.
2. In WHMCS, run “Unsuspend”.

- [ ] Unsuspend succeeds, and “Portal status” reads “Active”.

**D5 · normal · Suspended in WHMCS, active in Sign.net**

1. Run “Suspend” in WHMCS.
2. In the console's Accounts list, open the p01 row, click “Unsuspend”, and confirm with “Unsuspend”.
3. In WHMCS, click “Refresh from Sign.net”.
4. Run “Unsuspend” in WHMCS.

- [ ] After step 3:
  - “Needs attention” says “WHMCS shows this service Suspended, but the portal is active.”;
  - the Dashboard lists “Suspended in WHMCS, active in Sign.net”, with “The portal still opens for its users.”
- [ ] Step 4 succeeds.

### E. Plan changes

Also on the p01 service.

**E1 · high · Change an add-on's quantity**

1. On the service, set the quantity of the “5 extra seats” configurable option (`addon_QA1-SEATS`) to 3, and
   save.
2. Run Module Commands → “Change Package”.

- [ ] “Package” shows “Add-ons: QA1-SEATS × 3”, and the client area shows “5 extra seats × 3”.
- [ ] The activity log has “Sign.net: Add-on QA1-SEATS goes from 1 to 3 by replacing its attachment.
  (service #<id>)”.

**E2 · normal · Remove an add-on**

1. Set the quantity to 0 and save.
2. Run “Change Package”.

- [ ] The “Add-ons:” line is gone.

**E3 · high · Swap the package**

1. Change the service's product to QA Pro, set 5 extra seats to 1, and save.
2. Run “Change Package”.

- [ ] “Package” reads “QA Pro (QA1-PRO)”, with “Add-ons: QA1-SEATS × 1”.
- [ ] The activity log has “Sign.net: Package QA1-START is swapped: the portal holds no package for a moment, and
  its carried credit is written off. (service #<id>)”.
- [ ] The Dashboard's “Allocated now” for p01 has moved from QA Starter's quantities (Documents 100, Seats 5,
  Templates 3, Notarisations 0) to QA Pro's plus the add-on's 5 seats (500, 25, 10, 20).

**E4 · normal · Apply plan changes nothing when nothing changed**

Click “Apply plan”.

- [ ] It succeeds, and the portal's package and add-ons are unchanged.

**E5 · normal · An attached add-on's grants are fixed**

1. Go to Add-ons → QA1-SEATS → “Edit”.
2. Change the price to 6.00 and click “Save changes”.

- [ ] The “Granted per unit” boxes are disabled, with “This add-on has been attached to a portal, so what one
  unit grants can no longer change. Its name, description, price and status still can.”
- [ ] Saving says “Add-on QA1-SEATS was saved.”

**E6 · normal · A swap to an archived package changes nothing**

1. On the addon's Packages page, click “Archive” on QA1-START.
2. Change the p01 service's product to QA Starter and save. Run “Change Package”.
3. Click “Refresh from Sign.net”.
4. Click “Activate” on QA1-START, then change the service's product back to QA Pro and save.

- [ ] Step 2 answers “The package QA1-START is archived in Sign.net.”
- [ ] The activity log has no new “Sign.net: Package QA1-PRO is swapped…” line.
- [ ] After step 3, “Package” still reads “QA Pro (QA1-PRO)”, with “Add-ons: QA1-SEATS × 1”.

### F. Existing portals

**F1 · high · An address Sign.net already has**

1. In the console, click “Accounts” under “Private Label”, then “Provision Account”.
2. Set “Domain Path” to `p02.qa1.example.com`, and choose QA Starter under “Starts on”, where it reads “QA
   Starter (<price>)”. Fill in the owner and “App Name” as you like, and click “Provision Account”.
3. As the test client, order QA Starter with Portal address `p02.qa1.example.com`, and pay for it. WHMCS
   cannot see Sign.net's addresses, so checkout accepts it.

- [ ] After step 2, the console says “Account provisioned: p02.qa1.example.com, on its package.”
- [ ] The service stays Pending. Its Create failed with “p02.qa1.example.com is already in use on Sign.net, and a
  hostname can never be reused. Ask the customer for another portal address, or use "Link existing portal" if
  the portal is already yours.”
- [ ] The Dashboard lists “Provisioning failed”, with the same message.

**F2 · high · Link an existing portal**

1. On the F1 service, set Domain to `p02.qa1.example.com` and save. Its Create failed, so WHMCS has not filled
   the field in.
2. Click “Link existing portal”.
3. Click “Apply plan”.

- [ ] Step 2 succeeds.
- [ ] The Username becomes the console portal's tenant id.
- [ ] The Sign.net fields show p02.
- [ ] The activity log has “Sign.net: service #<id> linked to the existing portal p02.qa1.example.com.”
- [ ] Step 3 succeeds, and “Package” reads “QA Starter (QA1-START)”.

**F3 · normal · When linking is refused**

1. On the F2 service, click “Link existing portal” again.
2. Create a second QA Starter order in the admin area (Orders → Add New Order), and leave it unpaid.
3. Set that service's Domain to `p02.qa1.example.com`, save, and click “Link existing portal”.
4. Set its Domain to `p08.qa1.example.com`, save, and click it again.
5. Cancel that order.

- [ ] Step 1 says “This service already manages a portal. Unlink it first.”
- [ ] Step 3 says “That portal is linked to service #<id>.”
- [ ] Step 4 says “No portal of yours matches the tenant id in Username or the hostname in Domain.”

**F4 · high · Unlink, which keeps the portal**

1. On the F2 service, click “Unlink (keeps the portal)”.
2. Click “Link existing portal”.

- [ ] After step 1:
  - “Sign.net portal” reads “Unlinked: this service no longer manages a portal. Portal address:
    p02.qa1.example.com”;
  - the console still lists p02;
  - the activity log has “Sign.net: service #<id> unlinked from the portal p02.qa1.example.com; the portal
    itself was not changed.”
- [ ] Step 2 links it again.

**F5 · normal · Retry domain attach**

On the p01 service, click “Retry domain attach”.

- [ ] It succeeds and changes nothing, because p01 is already attached. It is the fix for a “Domain” that
  reads “Not attached”.

### G. Deleting portals

**G1 · critical · Terminate**

On the p01 service, run Module Commands → “Terminate” and confirm.

- [ ] “Portal status” reads “Deleted”, with “The portal was deleted, and its hostname can never be used again.”
- [ ] The client area shows “Deleted” and “This portal has been deleted.”
- [ ] The console no longer lists p01.
- [ ] The Dashboard's “Allocated now” has dropped by what p01 held: QA Pro's quantities, plus the 5 seats of
  its add-on.
- [ ] The activity log has “Sign.net: portal p01.qa1-dns.example.com deleted for service #<id>. Its hostname
  can never be used again.”

**G2 · high · Terminating again is safe**

Run “Terminate” on the same service again.

- [ ] It succeeds.

**G3 · high · A deleted portal's address stays taken**

1. In the admin area, create a QA Starter order (Orders → Add New Order) with its “Portal address” left blank,
   and leave it unpaid. Create reads “Portal address” first, so an address there would make a real portal.
2. Set its service's Domain to `p01.qa1-dns.example.com`, save, and run “Create”.
3. Cancel the order.

- [ ] Create is refused: “Service #<id> used p01.qa1-dns.example.com for a portal since deleted, and a hostname can
  never be reused.”

**G4 · normal · A portal deleted outside WHMCS**

1. On the console's Accounts list, click “Deprovision” on p02, which is linked to the F2 service, and confirm.
2. In WHMCS, click “Refresh from Sign.net” on that service.
3. Run “Terminate”.

- [ ] After step 2:
  - “Portal status” reads “Unavailable”, with “The portal p02.qa1.example.com no longer exists in Sign.net; it was
    deleted outside WHMCS. Unlink it, or terminate the service.”;
  - the Dashboard lists “Portal missing from Sign.net”.
- [ ] After step 3, Terminate succeeds and “Portal status” reads “Deleted”.

**G5 · normal · Deleting a WHMCS service whose portal is live**

1. Order and pay QA Starter on `p03.qa1.example.com`.
2. Delete that service in WHMCS.

- [ ] The activity log has “Sign.net: WHMCS service #<id> was deleted, but its Sign.net portal p03.qa1.example.com
  (tenant <id>) is still active. Terminate it in Sign.net, or link it to another service.”
- [ ] The Dashboard lists “WHMCS service deleted, portal still live”, against “#<id> (deleted)”.
- [ ] Deprovisioning p03 on the console's Accounts list (click “Deprovision” and confirm) clears the entry.

**G6 · normal · The cron deletes after a cancellation request**

1. Order and pay QA Starter on `p04.qa1.example.com`.
2. As the client, request its cancellation, choosing Immediate.
3. Run WHMCS's cron, or wait for its daily run.

- [ ] The service is cancelled, and the console no longer lists p04.

**G7 · normal · A product that never deletes automatically**

1. Set QA Starter's “Automated termination” to “Never”.
2. Order and pay it on `p05.qa1.example.com`, request cancellation with Immediate, and run the cron.
3. Run “Terminate” from the admin area.
4. Set the setting back to “Only after a cancellation request”.

- [ ] After step 2:
  - p05 still exists;
  - the module's answer to the cron's Terminate, in WHMCS's module queue, is “Not deleted: this product never
    deletes a Sign.net portal automatically. An administrator can terminate it from the service page.”
- [ ] Step 3 deletes it.

### H. The allowance

**H1 · critical · An order over the allowance is held**

1. Note Documents' “Remaining” on the Dashboard. Call it R.
2. Create a package `QA1-HUGE` “QA Huge” (Monthly, 1.00) with Documents set to R + 100. Leave Seats,
   Templates and Notarisations blank.
3. Create its WHMCS product with the server group from A2 and “Hold them for approval”.
4. As the client, order it on `p06.qa1.example.com` and pay.

- [ ] The service stays Pending. Its Create answered “[signnet:held] Held: this goes past your Sign.net
  allowance (documents: <R + 100> needed, <R> left (100 over)). Approve it on the service with "Approve
  over-allowance & retry", or ask Sign.net to raise your allowance.” WHMCS's module queue shows this too.
- [ ] The Dashboard lists “Order held: it would go over your Sign.net allowance”, with the shortfall and
  “Approve going over the allowance from the service's module commands, or free up allowance first.”
- [ ] Nothing was made: the console has no p06, and no welcome email was sent.

**H2 · critical · Approve it**

On the held service, click “Approve over-allowance & retry”.

- [ ] It succeeds. The service is Active, and the console lists p06 with QA Huge.
- [ ] WHMCS's welcome email “Your Sign.net portal is ready” arrives.
- [ ] Documents on the Dashboard shows “Over-allocated by 100”.

**H3 · high · Deleting gives the allowance back**

Run “Terminate” on the p06 service.

- [ ] Documents' “Remaining” returns to R, and the over-allocation flag is gone.

**H4 · normal · Confirming automatically**

1. Set QA Huge's “Orders over the allowance” to “Confirm automatically”.
2. Order and pay it on `p07.qa1.example.com`.
3. Terminate p07, and set the setting back.

- [ ] In step 2, the service goes Active with no hold.

**H5 · high · A swap held for approval leaves the portal as it was**

1. As the test client, order QA Starter on `p09.qa1.example.com` with “5 extra seats” set to 1, and pay.
2. On its service, change the product to QA Huge and save. Run “Change Package”.
3. Click “Refresh from Sign.net”.
4. Terminate p09.

- [ ] Step 2 answers “[signnet:held] Held: this goes past your Sign.net allowance (documents: <R + 100> needed,
  <R> left (100 over)). Approve it on the service with "Approve over-allowance & retry", or ask Sign.net to
  raise your allowance.”
- [ ] The activity log has “Sign.net: The new package could not be assigned, so QA1-START was put back with its
  add-ons. (service #<id>)”.
- [ ] After step 3, “Package” still reads “QA Starter (QA1-START)”, with “Add-ons: QA1-SEATS × 1”.
- [ ] After step 4, Documents' “Remaining” is R again.

### I. Logs and safety

**I1 · high · No secrets in the module log**

Open System Logs → Module Log, and search it for the part of the full key after its last underscore (the
secret).

- [ ] Nothing matches.
- [ ] The entries are named like “POST /api/v1/auth/token [<id>]”, then “GET /console/dashboard [<id>]” after
  each new token, and, for a new portal, “POST /console/reseller.qa1.example.com/reseller/private-labels
  [<id>]”.
- [ ] Wherever `api_key`, `access_token`, `confirmationKey` or `key` appears, its value reads `****`, and no
  entry holds an Authorization header.

**I2 · normal · The addon's forms need a fresh token**

1. Open Packages → “New package” and fill it in.
2. In another tab, sign out of the WHMCS admin area and back in.
3. Submit the first tab's form.

- [ ] WHMCS refuses the form, and no package is created.

**I3 · normal · Deactivating keeps everything**

1. Go to System Settings → Addon Modules → Sign.net Reseller → Deactivate.
2. Activate it again.

- [ ] Deactivating says “Sign.net Reseller is deactivated. Its portal links, data and settings are kept, so
  activating it again carries on where it left off.”
- [ ] After activating, the services keep their Sign.net fields (G1's still reads “Deleted”), and the
  settings from A5 are kept.

### J. WHMCS 9.0

1. On the WHMCS 9.0 install, do the [In WHMCS](#in-whmcs) setup steps.
2. Run every **critical** case again, in order, with the next run number: codes `QA2-…`, and addresses
   `p01.qa2-dns.example.com` and `p06.qa2.example.com`. The same test reseller and full key can be used.
   - Add three cases the critical ones rely on: A5 step 2 (for C9's colour), B7 (for B9's second product) and
     E3 (for G1's figures).
   - C8 needs the DNS record for `p01.qa2-dns.example.com`.
3. Finish with H3, so the reseller gets its allowance back.

## What this run proves

Beyond the plugin's own behaviour, the run checks the WHMCS behaviours the plugin assumes. If a case below
fails, the assumption is probably what broke.

| The plugin assumes | Checked by |
|---|---|
| Running Create through `localAPI('ModuleCreate')` sends the welcome email | H2 |
| `ClientAreaAllowedFunctions` takes the label => function form | C7 and C8: “Check again” runs |
| Custom fields reach the module HTML-encoded, so it decodes them | C5 and C9: the typed `&amp;` survives. If WHMCS does not encode, it arrives as `&`. |
| Cart custom fields are keyed by field id | C1: the typed address is the one checked |
| `check_token('WHMCS.admin.default')` refuses a stale token | I2 |
| The columns of `tblcustomfields`, `tblemailtemplates`, `tblproductconfig*` and `tblpricing` | A1 (the template), B6 and B7 (fields and pricing), B9 (the option group) |
| `-1.00` marks a billing cycle a product does not offer | B6: only Monthly is offered |

It also checks what the plugin assumes of Sign.net's reseller console, which it calls with the API key:

| The plugin assumes | Checked by |
|---|---|
| A key learns its reseller's console address (its primary host) only from `GET /console/dashboard` | A2, and I1: the log shows that call after each new token |
| A deleted portal answers `403 FORBIDDEN` and leaves the reseller's list | G4: “…no longer exists in Sign.net; it was deleted outside WHMCS…” |
| A taken address answers `HOST_TAKEN` | F1: “…is already in use on Sign.net…” |
| A package that gives no notarisations leaves the portal notarising from the reseller's pool | C7: no Notarisations line |
| Unassigning a package ends its add-ons, and they can be attached again straight away | H5: QA1-SEATS is back |

The run cannot settle two assumptions, and it does not need to:

- **Whether WHMCS stores a dropdown setting's key or its label.** The plugin reads either. G7 and H4 check
  that a setting changed on WHMCS's Module Settings form takes effect.
- **Whether WHMCS gives the Sign.net package dropdown the product's server.** When it does not, the plugin
  uses the addon's Sign.net server. With a single server, as here, both give the same list.

## Reporting a failure

Report each failure with the
[bug report form](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/issues/new?template=bug_report.yml), noting:

- the case id, WHMCS version and PHP version;
- the exact message, including any “(request <id>)” at its end, which finds the call in the module log;
- the module log entries around it. The key and tokens are masked (I1), but read them before you share them.

## Cleaning up

- Terminate every test portal still live. Their addresses are spent either way.
- Archive the QA packages and add-ons. They count towards the 100 whether or not they are archived.
- Delete WHMCS test services only after their portals are gone (G5 shows why).
- Keep the test reseller for the next run, with the next run number.
