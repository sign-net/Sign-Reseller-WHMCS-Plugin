# Installing and running Sign.net Reseller for WHMCS

This guide takes you from a fresh WHMCS to selling your first Sign.net portal, then covers
day-to-day operation. It assumes WHMCS 8.13 LTS or 9.0.

**Before you start**, have these from Sign.net:

- the API host, for example `api-app.sign.net`;
- an API key (`snk_live_…`; one from a Sign.net staging deployment starts `snk_test_…`) with the
  scopes `reseller:read`, `reseller:provision` and `reseller:packages`. You create keys in the
  Sign.net reseller console, and each key is shown only once;
- a Sign.net plan on your reseller account. Without one, Sign.net refuses to assign packages.

## 1. Upload the files

Download `signnet-whmcs-plugin-<version>.zip` from the
[latest release](https://github.com/sign-net/Sign-Reseller-WHMCS-Plugin/releases/latest) and unzip it.
Copy the two module folders inside it into your WHMCS root, keeping their paths:

```
modules/servers/signnet/             → <whmcs>/modules/servers/signnet/
modules/addons/signnet_reseller/     → <whmcs>/modules/addons/signnet_reseller/
```

Nothing else is needed. The plugin loads its own classes and uses no Composer at runtime.

## 2. Activate the addon

1. **System Settings → Addon Modules → Sign.net Reseller → Activate.** This creates the plugin's
   tables and the "Sign.net Portal Welcome" email template.
2. **Configure**: give your admin roles access, then set:
   - **Sign.net server**: the server the addon's pages use. Leave it on the first Sign.net server
     unless you have several.
   - **Portal defaults**: the support and website URLs, colours (`#rrggbb`) and features every new
     portal starts with. Anything left blank or on *Sign.net default* keeps Sign.net's own default.
     Customers can change their portal's look later from its Organisation page.
   - **Low allowance warning (%)**: below this percentage of an allowance item left, the
     dashboard warns you.

Deactivating the addon never deletes the plugin's tables, so your links between services and
portals survive a deactivate and reactivate.

## 3. Add the Sign.net server

**System Settings → Servers → Add New Server**:

| Field | Value |
|---|---|
| Name | Anything, for example *Sign.net* |
| Hostname | The API host, for example `api-app.sign.net` (no `https://` needed) |
| Module | **Sign.net Private Label** |
| Password | Your `snk_…` API key. WHMCS stores it encrypted. |
| Secure | Ticked (SSL). Leave the port blank for 443. |

Click **Test Connection**. It tells you if the key is refused, lacks a scope, or your account has
no Sign.net plan. After a refusal it waits five minutes before asking Sign.net about that key at
that address again; a corrected key or hostname is tried straight away. Then add the server to a
server group for your products.

## 4. Create packages and products

Open **Addons → Sign.net Reseller → Packages**.

1. **New package**: a code (permanent: a code can never be reused, even after the package is
   archived), a name, the billing cycle and price you sell it at, and what it includes (documents,
   seats, templates, notarisations). A portal given no notarisations notarises from your own pool, and
   you are billed what it uses. Changing a package's quantities later only affects portals that get it
   afterwards.
2. On the package's row, click **WHMCS product**, choose a product group, then **Create product**.
   The addon creates a product that:
   - uses the Sign.net Private Label module with this package;
   - is set up automatically when the order is paid;
   - sends the "Sign.net Portal Welcome" email;
   - is priced from the package;
   - asks the customer for **Portal address** (required) and **Portal name** on the order form.

   Or choose one of your existing products and click **Link product** to turn it into a Sign.net
   product.

To set a product up by hand instead, open its **Module Settings** tab, choose *Sign.net Private
Label*, and add two text custom fields: `Portal address` (required, shown on the order form) and
`Portal name` (optional).

### The product's Module Settings

| Setting | Choices |
|---|---|
| **Sign.net package** | Your active packages, read live from Sign.net. |
| **Orders over the allowance** | *Hold for approval* (default): an order that would take you past your Sign.net allowance waits, and nothing is created until you approve it. *Confirm automatically*: it goes ahead and you are billed for the excess. |
| **Automated termination** | *Only after a cancellation request* (default): the cron deletes a portal only when the client asked to cancel. *Always*: the cron deletes on any termination, overdue ones included. *Never*: only an administrator can delete. An administrator's own **Terminate** always deletes. |

## 5. Sell add-ons (optional)

Open **Addons → Sign.net Reseller → Add-ons**. Create one with **New add-on** (for example 5 extra
seats). Then click **Configurable option** on its row, tick the products that offer it, and click
**Create configurable option**. The customer chooses a quantity when ordering or upgrading, and the
module attaches that many units to their portal.

The configurable option is named `addon_<CODE>|<Name>`. Keep the part before the `|`: it is how the
module finds the add-on. Once an add-on has been attached to any portal, what it grants is fixed;
its price can still change.

## 6. The welcome email

The "Sign.net Portal Welcome" template (**System Settings → Email Templates**, *Product* group) can
use these merge fields:

| Merge field | |
|---|---|
| `{$signnet_portal_url}` | `https://` and the portal address |
| `{$signnet_portal_name}` | The portal's name |
| `{$signnet_portal_host}` | The portal address |
| `{$signnet_owner_email}` | The owner's email, where Sign.net sends the set-password link |
| `{$signnet_dns_records}` | The DNS records the customer still has to create, one per line |
| `{$signnet_domain_ready}` | `yes` once the address is served, otherwise `no` |

Sign.net emails the owner a set-password link that expires after 24 hours. If it expires, use
**Resend owner invite** on the service.

## What your customer sees

On the service's Overview in the client area:

- the portal's name, address and state: *Setting up*, *DNS pending*, *Active* or *Suspended*;
- the DNS records to create with their DNS provider, each with a Copy button, and **Check again**
  (at most once a minute);
- their package and add-ons;
- once the portal is live: **Open portal**, and a link to its Organisation page, where the owner
  changes colours, logos and people.

## Running it

### The service page

The **Sign.net** fields show what Sign.net reports about the portal: status, owner, package and
add-ons, users, and the domain with any DNS records still missing. They also list anything that
needs you, for example a held order, a failed call, or WHMCS saying *Active* while the portal is
suspended. The fields refresh at most once a minute; **Refresh from Sign.net** reads them now.

| Button | Use it when |
|---|---|
| **Apply plan** | The portal's package or add-ons don't match the service, for example after changing the product's package. |
| **Approve over-allowance & retry** | An order is held over your allowance and you want it to go ahead. The approval covers one run. |
| **Retry domain attach** | The domain shows *Not attached*. |
| **Resend owner invite** | The owner's set-password link expired. Sign.net sends at most one every five minutes. |
| **Link existing portal** | The portal already exists in Sign.net, for example one you made in the reseller console. Put its tenant id in the service's *Username* field, or its address in *Domain*, save, then click. |
| **Unlink (keeps the portal)** | This service should stop managing its portal. The portal itself is left as it is. |

### Held orders

A held order stays *Pending*. Its Create failed with a message that starts `[signnet:held]` and
says by how much it goes past your allowance, and it appears in WHMCS's module queue and on the
addon's dashboard. Approve it from the service page, or ask Sign.net to raise your allowance and
run **Create** again.

### Suspensions

Suspending signs everyone on the portal out and blocks sign-in. Their documents and the package are
kept, so you are still billed for the portal; terminating is what ends that. WHMCS's suspension
reason is passed to Sign.net, where you and Sign.net staff can see it; the portal's people never
do. If Sign.net itself suspended a portal, Unsuspend explains that only Sign.net can lift it.

### When something fails

- **Module log**: with module debug logging on (**System Logs → Module Log**), every call to
  Sign.net is logged, with the key and tokens masked. A failure's message ends with “(request <id>)”,
  and the same id marks that call in the module log.
- **Retrying Create is always safe.** If Sign.net's answer was lost, the next run looks for the
  portal before trying again, so it never makes a second one.
- **"The API key lacks a scope"**: create a key with all three scopes and paste it into the server's
  Password field.
- **Token minting is paused**: Sign.net allows five tokens per fifteen minutes per address, and the
  plugin shares one token across WHMCS. A refused key is paused for five minutes at that address:
  correct the key or the hostname and run **Test Connection**, which tries a corrected one straight
  away. A pause because that limit was reached lasts until the time the message gives.

## Upgrading

Copy the new release's module folders over the old ones. WHMCS runs the addon's upgrade when its
version changes, which adds any new tables or columns and never removes any.

## WHMCS 8.13 and 9.0

The plugin supports both. WHMCS 9.0 needs PHP 8.2 or later; 8.13 runs on PHP 8.1 to 8.3. Its
templates use only Smarty features WHMCS 9's Smarty 4 still supports.
