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
the user and enabling the methods they want under **Notification Settings**.

Only methods an admin has enabled globally (in the app
[settings](/settings.html)) are available here. Some methods need a per-user
detail so PriceBuddy knows where to send *your* alerts:

- **Pushover** — your user key
- **Apprise** — optional tag / config token overrides
- **Telegram** — your chat ID (message
  [@userinfobot](https://t.me/userinfobot) to find it, and start a chat with the
  bot first so it can message you)
- **Discord** — optionally your own channel webhook URL (otherwise the global
  default is used)
- **ntfy** — the topic you subscribe to in the ntfy app. Pick something hard to
  guess, since anyone who knows the topic can read it.

Email and Gotify use the global settings, so just enabling them is enough.

## Advanced

### Creating a user via the CLI

To create a user via the CLI, run the following command:

```shell
docker compose exec -it app php artisan make:filament-user
```
