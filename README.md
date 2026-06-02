# <img src="public/images/logo-full.svg" width="250" height="auto" alt="PriceBuddy">

**Stop overpaying. PriceBuddy watches the price of anything you want to buy and
tells you the moment it drops.**

PriceBuddy is a free, open source, self-hostable price tracker. Add a product
from almost any online store, and PriceBuddy will check the price for you every
day, chart its history, and notify you when it hits the price you've been
waiting for — all running on your own server, with your data staying yours.

[**📖 Read the docs**](https://pricebuddy.jez.me?ref=pb-gh) &nbsp;•&nbsp;
[**🚀 Install in minutes**](#installation) &nbsp;•&nbsp;
[**✨ Features**](#why-youll-like-it)

![Dashboard](docs/docs/.vuepress/public/screenshots/dashboard.png)

## Why you'll like it

### 🛒 Works with almost any store
No waiting for someone to add support for your favourite retailer. If a store
has a product page, PriceBuddy can almost certainly track it — out of the box or
with a few clicks. Many popular stores work the moment you paste a link.

### 💰 Compare prices across stores
Track the same product across multiple retailers (or multiple listings on the
same site, like eBay) and instantly see where it's cheapest right now.

### 📉 Know exactly when to buy
Every price is recorded over time and charted, so you can see the lows, the
highs, and the trend at a glance — and avoid a "sale" that isn't really a sale.

### 🔔 Get notified, your way
Set a target price or a price-drop threshold and let PriceBuddy do the watching.
Alerts can reach you in the app, by email, or through
**Pushover, Gotify, Apprise, Telegram, Discord and ntfy** — pick the ones you
already use.

### 📦 Availability tracking
See whether the cheapest option is actually buyable. PriceBuddy detects in
stock, pre-order, back order, special order, out of stock and discontinued so a
tempting price on an unavailable item doesn't fool you.

### ⚖️ Fair unit pricing
Comparing a 3-pack against a 10-pack? PriceBuddy can show the price *per unit*,
so a bigger box that's secretly worse value can't hide.

### 🔎 Find products to track
Connect your own [SearXNG](https://github.com/searxng/searxng) instance to
search for products and add them to PriceBuddy without leaving the app.

### 🏷️ Stay organised
Tag your products, filter your dashboard, and keep everything tidy — handy when
you're tracking a lot of things at once.

### 👥 Built for sharing
Multi-user support means everyone in the household gets their own products,
tags, settings and notification preferences.

### 🌗 Modern, mobile-friendly UI
A clean interface with light and dark mode that works great on your phone.

### 🔒 Yours to host
Open source and fully self-hostable. Run it on your own hardware and keep
complete control of your data.

## Screenshots

| Product overview | Price history |
| --- | --- |
| ![Product](docs/docs/.vuepress/public/screenshots/product.png) | ![History](docs/docs/.vuepress/public/screenshots/history.png) |

## Installation

All you need is [Docker](https://www.docker.com/).

The easiest way is with docker-compose. Grab a copy of
[docker-compose.yml](docker-compose.yml), tweak it to your liking, then run:

```shell
touch .env && docker compose up -d
```

With the defaults, the app is available at `http://localhost:8080` — log in with
`admin@example.com` / `admin` (change this straight away!).

See the [installation guide](https://pricebuddy.jez.me/installation.html) for the
full walkthrough and configuration options.

> Other installation methods aren't recommended given the app's dependencies,
> but if you'd rather not use Docker, look through `docker/php.dockerfile` and
> `docker-compose.yml` to see what's required.

## Background tasks

The Docker image has a cron job baked in that handles the background work —
fetching prices on your schedule and sending notifications. Nothing extra to set
up.

## Settings & configuration

The most common settings live on the in-app settings page. A handful of advanced
options can be set via the `.env` file. See the
[settings docs](https://pricebuddy.jez.me/settings.html) for details.

## Affiliate codes

By default, affiliate codes are added to a
[couple of stores](config/affiliates.php) to support development of the project.
Prefer not to? Set `AFFILIATE_ENABLED=false` in your `.env` or docker-compose
file. If you do, please consider supporting the project in
[other ways](https://pricebuddy.jez.me/support-project.html).

## Inspiration

PriceBuddy was largely inspired by
[Discount Bandit](https://github.com/Cybrarist/Discount-Bandit), a similar app
that lacked the flexibility to use any store without code changes.

## Contributing & development

Contributions are welcome — please open an issue or a pull request.

PriceBuddy is built with [Laravel](https://laravel.com) and
[Filament](https://filamentphp.com/), with a [Lando](https://lando.dev)-based dev
environment (`lando start`). Coding standards (Pint), static analysis (PHPStan)
and tests (Pest/PHPUnit) are enforced. See the
[development docs](https://pricebuddy.jez.me/advanced.html) for the full setup.

## Supporting development

Have a look [here](https://pricebuddy.jez.me/support-project.html) for ways to
support the project.

## License

See [LICENSE.md](LICENSE.md) for more information.

## Contributors

* [Jeremy Graham](https://jez.me)
