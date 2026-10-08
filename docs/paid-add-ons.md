---
title: Install a paid add-on
description: Install Odden Marketing or Odden Service with your license key, set up the license check, and keep the add-ons updated.
---

Odden Marketing and Odden Service are private packages, and both include the AI features. Composer downloads them from `packages.odden.io`, a registry that checks your license key. Everything else about installing them is the same as the open packages: they register themselves, load their own migrations, and need the open [Core](core/index.md) package.

## What you get when you buy

Right after you pay, you get an email with your **license key**, or one key for each site if you bought several. A key looks like `odl_...`.

**Keep that email.** We store only a fingerprint of each key, so we cannot show it to you again. If you lose a key you can make a new one from your license dashboard (below).

| You bought | Packages the key can download |
| --- | --- |
| Odden Marketing | `getodden/crm-marketing` |
| Odden Service | `getodden/crm-service` |
| The Suite | both |

Every paid package also installs `getodden/crm-license`, which handles the license check, and `getodden/crm-pro`, the engine behind the [AI features](https://odden.io/docs/marketing/ai) that both add-ons include. You do not install or buy it separately. A key only downloads the packages it covers: asking for another one answers "not found". Products bought together share one key; a product bought later is a separate purchase, so write to support@odden.io and we will add it to your existing key.

The email also has a button, **Add my licenses to my account**. It works once and expires after 30 days, so use it soon. See [Your license dashboard](#your-license-dashboard).

## 1. Tell Composer where the packages are

In your application's `composer.json`, add the registry:

```json
{
    "repositories": [
        { "type": "composer", "url": "https://packages.odden.io" }
    ]
}
```

Or from the command line:

```bash
composer config repositories.odden composer https://packages.odden.io
```

## 2. Give Composer your key

The key is the password. The username is not checked, so `license` will do:

```bash
composer config http-basic.packages.odden.io license odl_your_key_here
```

That writes it to `auth.json` next to your `composer.json`. **Do not commit `auth.json`**: add it to `.gitignore`. To store it for every project on your machine instead, add `--global`.

On a build server or in CI, put the same thing in the `COMPOSER_AUTH` environment variable instead of a file:

```json
{ "http-basic": { "packages.odden.io": { "username": "license", "password": "odl_your_key_here" } } }
```

## 3. Install the packages

Require only what you bought:

```bash
composer require getodden/crm-marketing
composer require getodden/crm-service
```

Then run the migrations, as for any Odden package:

```bash
php artisan migrate
```

If you use the [Filament admin](filament/index.md), the paid modules add their screens to it by themselves.

The paid packages need PHP 8.3 or newer, Laravel 12 or 13, and the open `getodden/crm-core` (Composer installs it for you). Each add-on's own documentation lists what to schedule and configure: Marketing and Service have their own commands to add to `routes/console.php`, and the AI features run on [your own Anthropic key](#the-ai-features-use-your-own-ai-key).

## 4. Set the key on your live site

Set the same key in the environment of the site that runs in production:

```bash
ODDEN_LICENSE_KEY=odl_your_key_here
```

A license is for **one live site**. Local, development, and staging copies are free: install them with the same `auth.json`, and leave `ODDEN_LICENSE_KEY` out of them.

## The daily license check

A production site (`APP_ENV=production`) with a key calls the license server once a day. It sends:

- the site's **domain**,
- the **versions** of the paid Odden packages, PHP, and Laravel.

That is all. No contacts, deals, tickets, or anything else from your CRM leaves your server. The answer says whether your license is active, in its grace period, or ended, and which versions you can install. You can run it by hand with `php artisan odden-license:check-in`.

- The check does nothing on local and staging sites, and when no key is set.
- A failed check of any kind is ignored: it never affects your site.
- Turn it off with `ODDEN_LICENSE_CHECK_IN=false`. Update notices then stop too.

When a Filament panel is present, the license package can show a short notice at the top of its pages about what the last check found. **It is shown to nobody until you say who.** Define the ability in your application:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('manage-odden-license', fn ($user) => $user->is_admin);
```

## What a license buys

A license decides one thing: **which new versions you can install with Composer.** You can install every version released while the license is paid, plus 30 days after.

**The add-ons keep working if you stop paying.** You keep every version you have, for as long as you like. Nothing is switched off. Updates just stop until you renew.

Update as usual:

```bash
composer update getodden/crm-marketing getodden/crm-service
php artisan migrate
```

A version released after your updates ended answers "Your license does not include this version". Renewing makes the newer versions available again.

## Version 0.9 and earlier

Odden Marketing and Odden Service were released as open source under the MIT license up to version 0.9. Those releases remain MIT-licensed, and you may keep using, modifying and redistributing them under that license.

Version 0.9 is the last open release. It is frozen: we do not maintain it, and it will not get new features, bug fixes or security updates. It is available in the public [`getodden/crm`](https://github.com/getodden/crm) repository at the `v0.9.0` tag.

The paid add-ons are the maintained versions. A license gives you every new version released while it is current, including fixes, the AI features, and support at support@odden.io. If you run 0.9 in production, plan to move to the paid add-on to receive security and compatibility updates.

## Your license dashboard

The **Add my licenses to my account** button in your email takes you to Odden Cloud, where you sign in or create an account and add the licenses to it. From the dashboard at `cloud.odden.io/licenses` you can:

- set each license's **domain**, for your own records and to spot a key used on more sites than intended (a site on another domain keeps working),
- see which sites use it,
- **make a new key** if you lost one or it leaked. The old key stops working at once, so update `auth.json` and `ODDEN_LICENSE_KEY` on every site that uses it,
- **transfer a license** to another Odden Cloud account, which agencies use to pass a site to the client who pays for it. The person needs an account already; write to support@odden.io if they do not have one.

If the link has expired, write to support@odden.io from the address you bought with.

## Several sites, packs, and agencies

Each production site needs its own license and its own key:

- **Five-site packs** give you five keys.
- **Agency subscriptions** give you one key for each site you subscribe for. Adding sites later adds more keys, and a license can be transferred to a client's account.

Install each site with its own key in its own `ODDEN_LICENSE_KEY`. They can all share one `auth.json`, since any valid key can download what it covers.

## The AI features use your own AI key

The AI features that come with Marketing and Service run on **your** Anthropic key, so nothing about them is billed by Odden. Set `ODDEN_AI_API_KEY` in your environment. Without it every AI feature falls back to the built-in version, and nothing breaks.

## Troubleshooting

| You see | What it means |
| --- | --- |
| `401` and "A valid license key is required" | The key is wrong, has a typo, was replaced by a new key, or was revoked (for example after a refund). Check `auth.json` or `COMPOSER_AUTH`. |
| `429` "Too many failed attempts" | Several wrong keys were tried from your address. Wait a minute and try again. |
| "Could not find package getodden/crm-service" | Your key does not cover that package, or no version is available to it. Check what your license covers on the dashboard. |
| "Your license does not include this version" | That version came out after your updates ended. Renew to install newer versions; the version you have keeps working. |
| It works locally but not in CI | CI needs the key too: set `COMPOSER_AUTH` as a secret there, never in the repository. |
| The daily check does not seem to run | It only runs when `APP_ENV=production`, a key is set, and the scheduler runs (`php artisan schedule:run` every minute). |

Anything else: write to support@odden.io, or reply to your keys email.
