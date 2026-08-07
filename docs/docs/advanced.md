# Advanced

This section contains advanced topics that are not required to use 
the app in most cases, but can be useful if you want to do more tricky
stuff.

## CLI

As PriceBuddy is built using Laravel, you have access to the excellent
[artisan CLI](https://laravel.com/docs/11.x/artisan) that comes with it.

To see all the available commands, run `php artisan` or via docker with:

```bash
docker compose exec -it app php artisan
```

### PriceBuddy specific commands

You can see all the available PriceBuddy specific commands by running
`php artisan buddy`

### Create/sync stores from code

All the "out of the box" stores can be found [here](https://github.com/jez500/pricebuddy/tree/main/database/seeders/Stores).
Any PRs adding additional stores are very appreciated!

You can sync stores from code by running `php artisan buddy:create-stores`. Note that this
will only add new stores, it will not remove any existing stores. You can also add the flag
`--update` to update existing stores (WARNING: this will overwrite any changes you have made
to these stores).

### Manually updating all prices

This is normally run as a scheduled cron task, but you can manually update
via `php artisan buddy:fetch-all`

### Regenerate price cache

For performance reasons, PriceBuddy caches prices against a product, on the 
rare occasion you want to regenerate this cache, you can run `php artisan buddy:regenerate-price-cache`

### Rebuild URL match keys

Rebuild the normalised match key for every tracked URL. Run after changing the tracking
parameter denylist: `php artisan urls:renormalize`
