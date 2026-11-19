# Settings

These are app wide settings that allow you to configure how PriceBuddy behaves.

## Scraper settings

These settings control how the scraper behaves. The scraper is responsible for
fetching the product details from the product URL.

**Fetch schedule** - A cron expression that defines when prices get auto-updated. 
The default is `0 6 * * *` which is 6am every day. This is the global schedule;
individual products can override it with their own
[check frequency](/products.html#check-frequency-pausing) or be paused entirely.

**Scrape cache ttl** - When a page is scraped, it will be cached for this amount 
of time. This is to prevent scraping the same page multiple times in a short period. 
This value is in minutes with the default of `720` minutes (12 hours).

**Seconds to wait before fetching next page** - To avoid being blocked by the
store, we wait this amount of seconds before fetching the next page. The default
is `10` seconds.

**Max scrape attempts** - If the scraper fails to fetch the page, it will retry
this amount of times. The default is `3` attempts.

## Locale

This is default locale settings for the app, it's primary use is for the scraper
to know what format the currency is and what currency to use. This will be used
as the default when creating a store and auto store creation.

**Locale** - This should match the locale/language of the stores you are scraping.
Eg. `en_US` for English (United States) or `fr_FR` for French.
**Currency** - This should match the currency of the stores you are scraping.
Eg. `USD` for US Dollars or `EUR` for Euros.

NOTE: You can also override this per store. If a product mixes stores with
different currencies, turn on [currency conversion](#currency-conversion), or the
price comparisons and aggregates will compare the raw numbers.

Each store saves its own locale and currency when it is created. If you change
the default later, existing stores keep their old values. To update them, go to
**Stores**, select the stores, and use the bulk action **Set locale and currency**.

## Currency conversion

Found under the **Currency** tab. Off by default.

When it is on, prices from stores that use a currency other than the default
currency (see [Locale](#locale)) are converted into the default currency. You can
then compare stores in different countries, and target price and percentage
alerts work across currencies. Lowest prices, sorting, aggregates, charts,
insights and the dashboard all use the converted prices.

Each price is converted with the exchange rate of the day it was found, so the
price history shows what each price was worth at the time. The original price
is shown next to the converted one on product cards, on the product page and in
price alerts. Hover over it to see the rate used.

Stored prices are never changed. Turning conversion off, or changing the
provider or default currency, rebuilds the price caches in the background,
and turning it off shows every price in its store's own currency again.

**Exchange rates** - Where the rates come from:

* **Frankfurter (European Central Bank)** - Free daily reference rates from the
  [ECB](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html)
  via [Frankfurter](https://frankfurter.dev). About 30 major currencies, no API key.
* **Yahoo Finance** - Free daily rates for most currencies, no API key. This is an
  unofficial API and can rate limit.
* **Manual rates** - Enter how much one unit of each currency is worth in the
  default currency. The same rate is used for every date.

Rates from Frankfurter and Yahoo Finance are fetched every day at 17:00 (app time)
for each currency your stores use, going back to your oldest price (up to a year).
Days without a rate, such as weekends, use the closest earlier rate. Use
**Fetch rates now** to get them straight away, or run:

```shell
php artisan buddy:fetch-exchange-rates
```

The **Rates in use** table lists every currency that needs converting and its
latest rate. A currency without a rate is shown in its own currency and is not
converted.

## Logging

**Log retention days** - The amount of days to keep logs for. The default is `30` days.

## Notifications

This is where global notification settings are configured. For a notification service
to work, it must first be enabled, then the appropriate settings added.

**Email** - Configure the SMTP settings for sending emails.

**Pushover** - Configure the Pushover application token for sending push notifications.

**Gotify** - Configure the Gotify server URL and application token for sending and testing push notifications.

**Apprise** - Configure the Apprise API server URL and configuration token.

**Telegram** - Configure the bot token used to send messages. Create a bot with
[@BotFather](https://t.me/botfather) and paste the token here. Each user then adds
their own chat ID in their account settings. Use the **Test bot token** button to
verify the token is valid.

**Discord** - Optionally configure a default channel webhook URL. Each user can
override this with their own webhook in their account settings. Use the **Test**
button to send a sample message.

**ntfy** - Optionally configure a server URL (leave blank to use the public
[ntfy.sh](https://ntfy.sh)) and, for protected self-hosted servers, a username and
password. Each user subscribes to their own topic in their account settings.

Note: these are global settings, each user must enable the notification method in their
own account settings. Depending on the notification method, they may also need to configure
additional settings (such as their Telegram chat ID, ntfy topic, or a personal Discord webhook).

## Integrations

This is where you can configure integrations with other services.

### SearchXNG
[SearXNG](https://github.com/searxng/searxng) is a self-hostable search engine that can be used to research products 
and add them to PriceBuddy. To use this feature, you must have a SearXNG instance and 
PriceBuddy must be able to access it. Additionally SearXNG must be configured to allow
returning results as JSON (See the [SearXNG documentation](https://docs.searxng.org/admin/settings/settings_search.html#settings-search)).

* You can a search prefix which will get added to each search query. Eg "Buy australia"
  is what I use.
* By default SearchXNG returns 30 items per page but here you can set how many pages
  to process if you want more results. Default is 1.

### AI providers

PriceBuddy can optionally use AI to recover missing prices and repair store
scraping rules. This is disabled by default and only runs once you configure at
least one provider here.

* **Add one or more providers** - Each provider has a type
  (OpenAI, Anthropic, Gemini or [Ollama](https://ollama.com)), a model, and —
  for cloud providers — an API key. API keys are encrypted at rest. Use the
  per-row **Test** button to check a provider is reachable before saving.
* **Default provider** - Choose which provider is used by default.
* **Per-feature provider** - Optionally pick a different provider for each
  feature (price *Extraction* and self-*Healing*), or turn an individual feature
  off entirely.

[Ollama](https://ollama.com) lets you run a model locally with no per-request
cost — set the base URL (e.g. `http://localhost:11434`) and pick a model from the
dropdown. Local models can be slower to respond, so allow a generous timeout.

Once a provider is configured, enable the features per store on the
[store](/stores.html#ai-price-extraction) edit page.

