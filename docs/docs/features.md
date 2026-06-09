# Features

## Works with almost any store

Many stores are included by default, but if your favourite store isn't there, 
you can add it yourself.

Stores can be scraped using CSS selectors, regular expressions or JSONPath.

## Compare prices from multiple stores

Add multiple urls to a product to compare prices from different stores
(or even the same store - eg eBay listings).

## Availability tracking

PriceBuddy can also track product availability such as in stock, pre-order,
back order, special order, out of stock or discontinued.

Availability is shown next to each product source so you can quickly see
whether the lowest listed option can actually be bought right now.

You can also opt in to **back-in-stock alerts** per product, so you're notified
the moment an out-of-stock item becomes available again.

## Unit pricing

You can compare products by unit price instead of only the shelf price.

This is useful for multi-packs, bundles, and products sold in different
pack sizes. For example, PriceBuddy can show the price per tablet, bag,
or other unit so cheaper-looking bundles do not hide a worse value.

## Flexible scheduling

Prices are fetched automatically on a schedule you control. You can also set a
custom check frequency per product (from every 5 minutes to every 24 hours), or
pause checking on individual products when you don't need them tracked right now.

## Price history

Visualise price changes over time with charts. See the min, max and average prices
Quickly identify trends and decide when to buy.

## AI-assisted scraping (optional)

PriceBuddy can optionally use an AI provider to help when normal scraping isn't
enough. It's disabled by default — connect a provider under
[Settings → AI providers](/settings.html#ai-providers) to turn it on.

- **AI price extraction** - When a scheduled scrape can't find a price, AI reads
  the page and recovers it. Enabled per store, it only fills a genuine gap and
  skips out-of-stock items.
- **AI self-healing** - When a store's scraping rules stop working, AI proposes
  fresh selectors to repair them, and can even bootstrap a brand new store from
  just a product URL. Can be disabled per store.
- **Bring your own provider** - Works with OpenAI, Anthropic, Gemini or a local
  [Ollama](https://ollama.com) model, so your data and costs stay under your
  control.

See [stores](/stores.html#ai-price-extraction) for how to enable it per store.

## Support for JS rendered sites

A headless browser can be used to scrape sites that require Javascript to render 
the page. This is done via [SeleniumBase](https://seleniumbase.io/) running chrome
in a docker container with a rest api.

## Organise your products

Tag products to better organise them. Tags can be used to filter products on the
dashboard.

## Multi-user support

Each user has their own products, tags and settings. Great for sharing with others
who want to track their own products.

## Notifications

Set a target price or a price-drop threshold per product and get alerted when
it's met. PriceBuddy supports a wide range of notification methods so you can be
reached however you prefer:

- In-app notifications
- Email
- [Pushover](https://pushover.net)
- [Gotify](https://gotify.net)
- [Apprise](https://github.com/caronc/apprise)
- [Telegram](https://telegram.org)
- [Discord](https://discord.com) (via webhooks)
- [ntfy](https://ntfy.sh) (including self-hosted servers)

Each method is enabled globally by an admin, then opted into per user, so
everyone controls how they're notified.

## Modern UI

Support for light and dark mode. Fully mobile friendly and easy to use.

## Integration with SearXNG

Use your instance of [SearXNG](https://github.com/searxng/searxng) to make it 
easier to search for products and add urls within the app.

## Open source and self-hostable

PriceBuddy is open source and self-hostable. You can run it on your own server
and have full control over your data. 
