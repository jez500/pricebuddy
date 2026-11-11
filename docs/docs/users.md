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

## Single sign-on (OIDC)

PriceBuddy can sign users in with any [OpenID Connect](https://openid.net/connect/)
provider, for example Pocket ID, Authentik, Authelia, Keycloak, Entra ID or Google.
It is off by default. When enabled, a **single sign-on** button is added to the
login page. Password login keeps working alongside it.

### Setup

1. In your OIDC provider create a new client (sometimes called an application).
2. Set its redirect (callback) URL to `{APP_URL}/login/oidc/callback`, for example
   `https://pricebuddy.example.com/login/oidc/callback`. It must match exactly,
   including `http` or `https`.
3. Add the environment variables below to the `app` service and restart.

```yaml
services:
    app:
        environment:
            OIDC_ENABLED: 'true'
            OIDC_ISSUER_URL: https://id.example.com
            OIDC_CLIENT_ID: your-client-id
            OIDC_CLIENT_SECRET: your-client-secret
```

| Variable | Default | Description |
| --- | --- | --- |
| `OIDC_ENABLED` | `false` | Turns single sign-on on. |
| `OIDC_ISSUER_URL` | | Base URL of your provider, without `/.well-known/openid-configuration`. Must be `https` (except for `localhost`). |
| `OIDC_CLIENT_ID` | | Client ID from your provider. |
| `OIDC_CLIENT_SECRET` | | Client secret from your provider. |
| `OIDC_REDIRECT_URI` | `{APP_URL}/login/oidc/callback` | Only set this if the callback URL differs from the default, for example behind a reverse proxy. It must also be registered with your provider. |
| `OIDC_LABEL` | `Single sign-on` | Text shown on the login button. |
| `OIDC_AUTO_REGISTER` | `true` | Create a PriceBuddy user the first time someone signs in. Set to `false` to only allow users that already exist. |
| `OIDC_LINK_BY_EMAIL` | `true` | Sign in as the existing PriceBuddy user with the same email address. Set to `false` to refuse them instead. |
| `OIDC_ADMIN_GROUP` | | Optional. Members of this group are **Admins**, see [roles from groups](#roles-from-groups). |
| `OIDC_GROUPS_CLAIM` | `groups` | Name of the claim in the token that lists a user's groups. |
| `OIDC_SCOPES` | `openid email profile` | Scopes to request. `groups` is added automatically when `OIDC_ADMIN_GROUP` is set. Only change this if your provider uses a different scope name for groups. |

The provider must return an email address (the `email` scope is requested). A sign
in without one is refused.

### How users are matched

The first time someone signs in, PriceBuddy looks for a user with the same email
(case insensitive). If one exists they are signed in as that user and the two are
linked, so later sign ins follow the provider account rather than the email.
Otherwise a new user is created with the **User** [role](#user-roles), which an
admin can change afterwards.

### Roles from groups

By default roles are managed in PriceBuddy, so new single sign-on users are **Users**
and an admin promotes them. To manage roles in your provider instead, create a group
there (for example `pricebuddy_admins`), add your admins to it, make sure the
client can see the groups, and set `OIDC_ADMIN_GROUP` to the group name.

- Members of the group are **Admins**, everyone else is a **User**.
- The role is checked on every sign in, so removing someone from the group removes
  their admin access the next time they sign in.
- Group names must match exactly, including case.
- If the token has no groups claim at all, roles are left unchanged and a warning
  is logged. This usually means the provider is not sending groups to the client.
- The last remaining Admin is never demoted, so a wrong group name can not lock
  you out.

::: warning
Linking by email trusts your provider to have verified each address. Only enable
`OIDC_LINK_BY_EMAIL` and `OIDC_AUTO_REGISTER` with a provider you control, or
one that only holds people you want to give access to. With a public provider
anyone could register and sign in.
:::

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
