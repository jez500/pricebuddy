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
This initial user is created with the **Admin** role so they can access the
[settings](/settings.html) and manage other users.

## User roles

Every user has a role that controls what they can do. There are two roles:

| Role | Capabilities |
| --- | --- |
| **Admin** | Full access. Can manage the global app [settings](/settings.html) and create, edit and delete other users, in addition to everything a normal user can do. |
| **User** | Standard access. Can manage their own products, tags and account, but cannot see the Settings menu or manage other users. |

New users default to the **User** role. If a user is missing the **Settings**
menu on the left, their role is **User** — promote them to **Admin** by editing
the user in the Users page, or via the [CLI](#assigning-a-role-via-the-cli).

> To avoid locking yourself out, the Users page prevents deleting the last
> remaining Admin (and deleting your own account). Note that the
> [CLI command](#assigning-a-role-via-the-cli) applies roles directly and does
> **not** enforce this, so take care not to demote your only Admin.

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

### Assigning a role via the CLI

To change a user's [role](#user-roles) from the CLI, use `user:assign-role`. It
takes the user's email and the role to assign (`admin` or `user`):

```shell
docker compose exec -it app php artisan user:assign-role user@example.com admin
```

Run it without arguments to be prompted interactively — search for the user by
name or email, then pick a role:

```shell
docker compose exec -it app php artisan user:assign-role
```

This is handy for promoting the first user to **Admin** if they were created
without the role, or for recovering access if no Admin remains.
