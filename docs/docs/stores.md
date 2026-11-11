# Stores

A store tells PriceBuddy how to scrape the price (and other information) 
from a website. A number of stores are pre-configured, but you can also
add your own.

Stores are shared between all users in PriceBuddy, so if you add a store
it will be available to all users. Any user can edit a store. Only an admin
can delete a store, because deleting it also deletes every user's URLs and
prices for that store.

Below will go into more detail on how to add or configure your own stores.

## Store name

As you would expect, this is just used for store identification in the UI.

## Domains

This is a list of domains that the store is valid for. When you add a product
URL, the domain is extracted then PriceBuddy will do a lookup for any stores
with a matching domain name. Once a match is found the settings are used to
scrape content from that stores product page.

You can add more than one domain to a store, for example, `amazon.com` and 
`www.amazon.com`.

## Strategies

These are the rules that PriceBuddy uses to extract the price, title, image
and optionally availability from the product page. There is multiple ways to
extract these details and you can mix and match different strategies.

### Schema.org (JSON-LD)

Probably the most robust way to extract data from a product page, this method 
reads the JSON-LD data to extract the product detail.

You can read about the spec [here](https://schema.org/Product). It will only
work if the retailer has added the JSON-LD data to the page, but if it does
exist, this is the recommended method.

When a store is auto-created, it will test this strategy first.

### CSS Selector

This is the most common strategy and is used to extract data. There are plenty
of tools and resources to help with this, but the most common way is to use
the browser developer tools to inspect the page and find the element you want.

Right click on the data you want to extract and select `Inspect` from the menu,
The developer tools will open and highlight the element in the DOM. Right click
on the element and select `Copy` then `Copy selector`. You can then paste this
into PriceBuddy.

#### Example for getting the price using CSS Selector

On `amazon.com` we use developer tools to get the selector `span.a-price`

![CSS Selector](/css-selector.png)

Then in PriceBuddy, we add the selector `span.a-price` to the price field.

![CSS Selector Price](/css-selector-price.png)

#### More about CSS Selectors

There is plenty of ways to select elements in CSS, you can use classes (eg `.price`), 
ids (eg `#price`) or even attributes (eg `[data-name="price"]`). Some Googling will
teach you more here, for example [this resource](https://www.geeksforgeeks.org/css-selectors/).

#### Getting the value of an attribute

If the data you want to extract is part of an attribute, you can use the `|` symbol
to get the value. For example, if the html looked like this:

```html
<div class="product" data-price="10.00">
```

You would use the selector `.product|price` to get the value `10.00`.

#### The most common CSS Selectors for extracting data

* Title - `meta[property=og:title]|content`
* Price - `meta[property=og:price:amount]|content`
* Image - `meta[property=og:image]|content`

But every site is different.

## Availability strategy

Availability is optional, but recommended for stores where products can be
pre-order, back order, sold out, or discontinued.

To configure it:

* Add an availability strategy to scrape the availability text from the page
* Add match values to map that scraped text to a status
* Choose a default status when no match is found

This lets PriceBuddy show stock information in the product view and avoid
treating unavailable items as normal price matches.

When the availability strategy type is **Schema.org**, PriceBuddy infers the
status directly from the page's schema.org availability value, so you don't need
to configure match values for it.

### Missing price means out of stock

Some stores, such as Amazon, remove the price from the page when a product is out
of stock. Normally a missing price is a scrape error, so the product cannot be added.
If AI self-healing is enabled, it can also change the price selector to a different
price on the page, such as the price of a related product.

To prevent this, enable **Treat a missing price as out of stock** in the store's
scraper service settings. A page that has a title but no price is then marked as
out of stock. You can still add the product, and AI self-healing does not run.

A page without a title is still an error. A broken price selector on this store also
looks like out of stock, so check the store with **Test** (see [Testing a store](#testing-a-store)) if many of its products
show as out of stock.

### Regex

Regular expressions are a powerful way to extract data from a page. It is more 
complex to use than CSS selectors but can be more flexible. 

#### Example for getting the price using Regex

If the html contained something like this:

```html
{"price": "10.00", "currency": "USD"}
```

We could use the regex `~\"price\": \"(.*?)\"~` to extract the price. 

#### Tools for testing Regex

One of the best tools for testing regex is [regex101](https://regex101.com/). Paste the 
"source" of your page into the "Test String" box and your regex into the
"Regular Expression" box. You can then see what matches your regex will find.

### JSON Path

JSON Path is a way to extract data from a JSON object. This is useful when the
data source is an API that returns JSON. The format used is more "dot notation" 
than JSON path, but it is similar.

#### Example for getting the price using JSON Path

If your JSON looks like this:
```json
{
  "product": {
    "title": "Product Name",  
    "price": 10.00
  }
}
```

You would use the JSON Path `product.price` to extract the price.

## Locale

This is default locale settings for the store, the default can be set in 
[Settings](/settings.html) but this will override that for this store.

**Locale** - This should match the locale/language of the store.
Eg. `en_US` for English (United States) or `fr_FR` for French.
**Currency** - This should match the currency of the store.
Eg. `USD` for US Dollars or `EUR` for Euros.

NOTE: Mixing currencies on the same product results in incorrect price 
comparisons and aggregates.

To change the locale and currency of many stores at once, select them in the
store list and use the bulk action **Set locale and currency**. The store list
shows the currency of each store.

Saved prices do not store a currency. PriceBuddy shows them in the store's current
currency. If you change the currency, saved prices keep their numbers and are not
converted. Use this to correct a store that has the wrong currency. Do not use it
when a store changes the currency it sells in, because old and new prices then mix.

## Price adjustments

The price on a store's page is not always the price you pay. A store in another
country may show its own country's tax and add yours at checkout, and many stores
add shipping. Price adjustments make PriceBuddy save the price you pay instead, so
target prices, alerts, history and insights all compare what you would really pay.

* **Page price includes tax (%)** - The tax already in the price on the page.
* **Tax you pay (%)** - The tax you pay at checkout.
* **Flat shipping fee** - Added to every price, in the store's currency. It is used
  when the store has no [shipping strategy](#shipping-strategy), or when that strategy
  finds nothing on the page.
* **Free shipping from** - The flat shipping fee is free when the price after tax
  reaches this amount, for stores with "free shipping from €50" offers. An item at
  exactly the amount ships free. Leave it at 0 for no threshold. Shipping read from
  the page is used as it is, since the page already says when an item ships free.
* **Apply tax to shipping** - Off: shipping is added as entered. On: shipping gets the
  same tax adjustment as the page price, such as import VAT charged on the goods and
  the delivery.

The two tax fields swap one tax for the other. Examples:

* A German store showing 19% VAT, delivering to Portugal where you pay 23%: `19` and
  `23`. A page price of €324.80 becomes €335.72.
* A store showing prices without tax, where you pay 23%: `0` and `23`.
* Removing a 23% tax, for example when buying as a business: `23` and `0`.

Leave everything at 0 when the page shows the price you pay. Stores are shared, so
the adjustments apply to every user's prices from the store.

Each product's listings can still [ignore shipping](/products.html#ignore-shipping), for
the stores where you usually combine orders.

PriceBuddy keeps the page price next to the price you pay. Wherever a price is not the
page price, it is marked with `*`, and hovering over or tapping the `*` shows what it is
made of, such as `€324.80 + €10.92 tax + €7.65 shipping`. Price alerts include the same
breakdown on a line of their own.

### Applying changes to past prices

When you change a store's price adjustments, choose which prices the new settings
apply to before saving:

* **All past prices** - Every saved price from the store is recalculated from its page
  price. Use this when you first set up a store.
* **Prices since a date** - Only prices saved on or after that day are recalculated.
  Use this when a tax rate changed on a known date.
* **New prices only** - Saved prices stay as they are.

The recalculation runs in the background, in batches, so the queue worker must be
running. It uses the settings you saved, sends no alerts, and leaves alone prices saved
after it started, which already use those settings. One recalculation runs per store
at a time: while it runs, saving changed settings for all past prices or since a date
asks you to wait, and "New prices only" can still be saved.

To recalculate later, use **Recalculate prices** at the top of the store page. It works
out saved prices again with the saved settings, for all past prices or since a date.
Use it if a recalculation stopped with an error (it is logged), or after changing
settings with "New prices only".

Changing a store's price adjustments through the API applies them to new prices only.

### Shipping strategy

A shipping strategy reads the shipping cost from the product page, for stores where it
depends on the item. It works like the other strategies, except that it cannot use
Schema.org. A value with a number in it is the shipping cost, a value without one, such
as `FREE`, is free shipping, and when the strategy finds nothing the flat shipping fee is
used and a warning is logged. Point the strategy at the shipping cost only: a text such
as "Free delivery over €35" would read as €35.

On Amazon, which shows the delivery cost to your location, this reads it:

```
#mir-layout-DELIVERY_BLOCK [data-csa-c-delivery-price]|data-csa-c-delivery-price
```

Use [Test](#testing-a-store) to see what the strategy finds. When past prices are
recalculated, the shipping read from their pages is counted while the store has a
shipping strategy. Without one, the flat fee is used, but the amounts are kept, since a
past page cannot be read again: adding the strategy back counts them again.

## Cookies

Some stores only show the correct product page or price when a cookie is sent
with the request.

You can add cookies to a store so PriceBuddy includes them whenever it scrapes
that site. This can help with stores that require a location, age check, login,
or other session-based preference before they reveal the real product data.

## Scraper service

This is what PriceBuddy uses get the HTML of the product page. There are two
services available:

### Curl based HTTP request (HTTP)

This is the default and preferred method. It gets the HTML of the page using a basic
HTTP request, this is the same as what you would get if you "view source" on a
webpage. 

It is the fastest and most reliable method, however many modern websites require 
JavaScript to render the page. This method will not work on those sites.

### Browser based request (API)

This method uses a headless browser to render the page and get the HTML. This is
means that JavaScript is executed and the page is rendered as if you were viewing
it in a browser.

We use [SeleniumBase Scrapper](https://github.com/jez500/seleniumbase-scrapper) to do this, which is a 
docker image running a headless browser. Internally it uses [SeleniumBase](https://seleniumbase.io/).

There are many advanced settings you can use with this service if the site you are
scraping is proving difficult to get the data from. 

The [SeleniumBase Scrapper docker image](https://hub.docker.com/repository/docker/jez500/seleniumbase-scrapper)
is interchangeable with [Scrapper](https://github.com/amerkurev/scrapper) and was based on the scrapper api.

If you are using Scrapper, it provides its own web interface for testing and debugging, if you're using
the default `docker-compose.yml` you can access this at `http://localhost:3000`. 

## Auto store creation

PriceBuddy can auto create stores for you given only the product URL. This works by
attempting to scrape the page and extract the price via common strategies. After a 
successful strategy is found the store will be created.

This will not work with all stores, but it does work for most. It will only use the
Curl based HTTP request to get the page contents for performance reasons.

If auto creation fails, try manually creating the store and using the API scraper or
more custom strategies.

## Testing a store

When editing a store, use **Test** to try a product URL and see exactly what the
current strategies extract. Results are shown in a comparison table (title,
price, image, availability, shipping and more) so you can confirm each field before
saving. You can switch the [scraper service](#scraper-service) and re-test, and —
if an [AI provider](/settings.html#ai-providers) is configured — use **Compare
with AI** to see what AI extracts from the same page alongside the scraped
values.

## AI price extraction

If an [AI provider](/settings.html#ai-providers) is configured, you can enable
**AI price extraction** per store. When a normal scrape finds no price, AI reads
the page and tries to recover one.

It is purely additive: it only fills a genuine gap, never overrides a scraped
price, skips items detected as out of stock, and only accepts a confident
result. You can optionally choose a specific provider for the store.

## AI self-healing

When a store's strategies stop working (for example the site changes its
markup), **AI self-healing** can propose fresh selectors to repair the store
config — and can even bootstrap a new store from just a product URL. PriceBuddy
tries deterministic heuristics first and only escalates to the AI provider when
needed.

Before it changes a store, self-healing checks the current config against
another in-stock product of the same store that has a price. If the config
still finds a title and price there, the store is not broken. The failing page
is probably unavailable or removed, so self-healing leaves the config unchanged.

From the store [test](#testing-a-store) view you can run **Heal with AI** to
preview a proposed fix and apply or discard it; nothing is saved until you choose
to. Self-healing can be turned off per store with **Disable AI self-healing for
this store**.

Requires an [AI provider](/settings.html#ai-providers) with the *Healing*
feature enabled.

## Sharing a store

If you create a store and want to share it with others, you can export the store as
JSON by clicking the "Share" button. You can then give this JSON to others and they
can import it into their PriceBuddy instance.

The export leaves out the store's [price adjustments](#price-adjustments), because tax
and shipping depend on where the buyer lives. An import ignores them too. The shipping
strategy is part of the export.
