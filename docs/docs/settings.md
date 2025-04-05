# Settings

These are app wide settings that allow you to configure how PriceBuddy behaves.

## Scraper settings

These settings control how the scraper behaves. The scraper is responsible for
fetching the product details from the product URL.

**Fetch schedule time** - Prices will be scraped once a day, this setting lets 
you control when that happens.

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

NOTE: You can also override this per store, but mixing currencies on the same
product results in incorrect price comparisons and aggregates.

## Logging

**Log retention days** - The amount of days to keep logs for. The default is `30` days.

## Notifications

This is where global notification settings are configured. For a notification service
to work, it must first be enabled, then the appropriate settings added.

**Email** - Configure the SMTP settings for sending emails.

**Pushover** - Configure the Pushover settings for sending push notifications.

**Gotify** - Configure the Gotify settings for sending and testing push notifications.

Note: these are global settings, each user must enable the notification method in their
own account settings.

## Integrations

This is where you can configure integrations with other services.

**SearXNG** - [SearXNG](https://github.com/searxng/searxng) is a self-hostable search engine
that can be used to search for products and add them to PriceBuddy. To use this feature, you
must have a SearXNG instance and PriceBuddy must be able to access it. Additionally
SearXNG must be configured to allow returning results as JSON (See the 
[SearXNG documentation](https://docs.searxng.org/admin/settings/settings_search.html#settings-search)).

