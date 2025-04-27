# Frequently asked questions

## I get the incorrect price when scraping a store

This can happen for a few reasons and generally shouldn't be considered a bug in 
PriceBuddy.

### The store scraping strategy is incorrect

This is more often a problem when a store is auto created as it just attempts some 
common strategies until it get one that looks like it works (but could be incorrect). 
Troubleshooting steps are editing the store, adjusting the strategy and testing it 
until you get the expected price.

### The store has changed their website

This is a common problem with scraping. If a store used to work but does not any more,
it is likely that the store has changed their website. Troubleshooting steps are editing
the store, adjusting the strategy and testing it until you get the expected price.

### You have the wrong locale or currency settings

Different regions use different formats for currency and language. If you have the wrong
locale or currency settings, the price will be incorrect. When a store is auto created, 
it will use the default locale and currency (see settings page). If a specific store uses
a different locale or currency, you can override it in the store settings.

### The store is blocking PriceBuddy

PriceBuddy is a scraper and as such, it can be blocked by stores. PriceBuddy tries to
help with this by acting like a real human (eg changing user agent, waiting between, 
requests etc) but some stores are very aggressive with blocking. If you suspect this is
the case, try accessing the store in a browser and see if you are blocked.

### The store uses JavaScript to render the page

Some stores use JavaScript to render the page, this means that the values you want to
scrape (eg price) are not in the HTML but are added after the page has loaded. PriceBuddy
supports browser base scraping for this, but it is slower and more resource intensive, 
but may be the only option for some stores. You can change the scraper service in the
store settings. 

The browser based scraper has many settings available: [docs here](https://github.com/amerkurev/scrapper).
Consider adjusting timeouts, sleep settings, etc. In extreme cases you could use user
defined JavaScript to extract the price (you would need to mount the scripts in the
scrapper container and reference them in the store settings).

### The store requires authentication to view prices

Some stores require you to be logged in to view prices. PriceBuddy does not currently
support this, potentially you could use the browser based scraper with custom scripts
to work around this but is very untested.
    
Eg mount the script in the scrapper container and reference it in the store settings:
```
user-scripts=/store-x-post-load-script.js
```

### The store uses geo-blocking or geo-pricing

The store may require you to select a language or region before you can view prices.
This is often stored in the user session rather than the url. The best fix to this
is to try and find a direct link to the product that bypasses the region selection.

## The image is not displaying on the dashboard

The image is fetched when the product is first scraped, if the image is not displaying, 
it could be for the same reasons as the price not displaying. You can always set the
image manually by editing the product and adding the image url

## Products are not displaying on the dashboard

The dashboard only shows your favourite products, edit the product and check the 
"Favourite" checkbox. If you have no favourite products, the dashboard will be empty.

## Can I share my stores with other users?

Yes! If you edit a store, you will see a "Share" button. This will generate the JSON
for the store which could be shared with others. To import a store, go to the stores
page and click "Import" and paste the JSON.
