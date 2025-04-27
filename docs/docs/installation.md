# Installation

The recommended way to install PriceBuddy is via Docker. This will ensure that all 
dependencies are installed and configured correctly. A `docker-compose.yml` file is
provided to make this easy.

## Quick start

```shell
mkdir pricebuddy
cd pricebuddy
wget https://raw.githubusercontent.com/jez500/pricebuddy/main/docker-compose.yml
touch .env
docker compose up -d
```

Then visit `http://localhost:8080` in your browser. The default username is 
`admin@example.com` and the default password is `admin`.

NOTES:
* You should look at the `docker-compose.yml` to ensure you are happy with the default
  settings, ports, environment variables, etc.
* If you are getting a 500 error, it is likely due to a missing `.env` file. You can
  create an empty one next to the `docker-compose.yml` file with `touch .env`.

## Database

The docker compose file includes a mysql database. You can use an external database if
you prefer, see [Laravel documentation](https://laravel.com/docs/11.x/database#introduction)
for supported databases. 

The docker image includes `mysql` and `pgsql` drivers. Note `sqllite` is not supported 
due to the use of `json` columns.

## Persistent storage / volumes

The docker compose file includes volumes for the database and the app. Not much is 
stored in the app volume so it won't need a lot of space.

## Environment variables

You can refer to [.env.example](https://github.com/laravel/laravel/blob/11.x/.env.example)
file for the environment variables that can be set.

If you just use the variables set in the docker compose file, you should be good to go.

## Reverse proxy configuration

If you are using a reverse proxy (eg nginx) to access the app, you may have issues with
the CSS and JS not loading. This is due to the app not being able to determine the
correct URL to use. You can fix this by setting the `APP_URL` and `ASSET_URL` environment
variables to the correct URL.

## Debugging install

The `docker-compose.yml` file has been tested to get you up and running quickly. You 
should create an `.env` file next to the docker-compose file otherwise docker will
create a directory. The installation should setup the database schema, add default 
content and users.

If you have any issues, you can run the following commands to see what is happening:

```shell
docker compose exec -it app cat /app/storage/logs/laravel.log
```

You can also enable debugging via environment variables:

```shell
APP_ENV=local
APP_DEBUG=true
```


