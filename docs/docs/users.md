# Users

PriceBuddy is a multi-user application, each user has their own products and settings. 
Users can be created by an existing user going to the Users page or via the CLI.
To create a user via the CLI, run the following command:

```shell
php artisan make:filament-user
```

## Initial user

If you set the environment variable `APP_USER_EMAIL` and `APP_USER_PASSWORD` 
when running the docker container, a user will be created with those credentials.

## Products and tags are per user

The current logged in user will only see their own products and tags. Stores are
shared between all users.

## Notifications

A user must opt in to notifications to receive them. This can be done by editing
the user. Some notification methods may need additional configurations for them 
to work.

## Advanced

### Creating a user via the CLI

To create a user via the CLI, run the following command:

```shell
docker compose exec -it app php artisan make:filament-user
```
