# Products

A product is a single item that you want to track the price of. This could be a 
pair of shoes, a book, or a computer. Basically, anything that that has a product
webpage that you can visit.

Products are unique to the user who has added it, so you will only see the products
that you have added to your account.

## Product URLs

A Product can have many URLs. Each URL is a different place where you can buy the
product. For example, a book might be available on Amazon, Barnes and Noble, and eBay.
All of these URLs would be associated with the same product and would be tracked.

When creating a new product, you start with a product URL. PriceBuddy will use this
URL to get the product details and price. You can then add more URLs to the product.

You can also add a URL to an existing product.

## Unit pricing

When a product comes in different pack sizes, you can add a **Price Factor**
to a URL so PriceBuddy also shows the unit price.

Example:

* A 34-pack of tablets can use a price factor of `34`
* A bundle of 3 coffee bags can use a price factor of `3`

If you want, you can also add what the product is sold as, such as
`tablets`, `bags`, or `100g`.

PriceBuddy will then show:

* The unit price as the main comparison
* The normal retail price underneath

## Availability

When supported by a store, PriceBuddy will also show the availability of each URL.
This helps you tell the difference between the cheapest price and the best option
that is actually available to buy.

Possible availability states include:

* In Stock
* Pre-Order
* Back Order
* Special Order
* Out of Stock
* Discontinued

## Price History

Each URL has a price history. This is a list of prices that the product has been
sold for at that URL. This allows you to see how the price of the product has changed
over time.

When viewing a product the most recent price for each URL is displayed. The prices 
are listed lowest price to highest price. This allows you to quickly see where the
product is cheapest.

If a URL is unavailable and there is no current price, PriceBuddy will show the
availability status instead of displaying a misleading zero price.

Price history charts can also switch between retail price and unit price.

## Price trends

For each URL, PriceBuddy will show the price trend compared to the previous price.
This will show you if the price has gone up, down, or stayed the same. This can help
you decide if you should buy the product now or wait for the price to drop.

## Notifications

You can set up notifications for a product. This will email you when the price
of the product drops below a certain price. This allows you to get the best deal on
the product without having to constantly check the price yourself.

Unavailable items do not trigger price alerts until they have a valid current
price again.

Notifications require the following settings:

#### Product settings

* **Notify price** - The price must be equal or less than this value for the 
  notification to be sent.
* OR **Notify percent** - The price must be this percentage less than the initial
  price for the notification to be sent.

#### Global settings

A notification method configured, eg email smtp settings.

#### User settings

The user has enabled notification methods they want.
