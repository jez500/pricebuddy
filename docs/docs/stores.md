# Stores

A store tells PriceBuddy how to scrape the price (and other information) 
from a website. A number of stores are pre-configured, but you can also
add your own.

Stores are shared between all users in PriceBuddy, so if you add a store
it will be available to all users.

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

These are the rules that PriceBuddy uses to extract the price, title and 
image from the product page. There is multiple ways to extract these details
and you can mix and match different strategies.

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

We use [Scrapper](https://github.com/amerkurev/scrapper) to do this, which is a 
docker image running a headless browser. Internally it uses both 
[Playwright](https://github.com/microsoft/playwright) and 
[Readability](https://github.com/mozilla/readability).

There are many advanced settings you can use with this service if the site you are
scraping is proving difficult to get the data from. See the 
[Scrapper Github page](https://github.com/amerkurev/scrapper) for documentation.

Scrapper provides its own web interface for testing and debugging, if you're using
the default `docker-compose.yml` you can access this at `http://localhost:3000`.

## Auto store creation

PriceBuddy can auto create stores for you given only the product URL. This works by
attempting to scrape the page and extract the price via common strategies. After a 
successful strategy is found the store will be created.

This will not work with all stores, but it does work for most. It will only use the
Curl based HTTP request to get the page contents for performance reasons.

If auto creation fails, try manually creating the store and using the API scraper or
more custom strategies.

## Sharing a store

If you create a store and want to share it with others, you can export the store as
JSON by clicking the "Share" button. You can then give this JSON to others and they
can import it into their PriceBuddy instance.
