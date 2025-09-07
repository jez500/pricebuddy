# REST API

You can access PriceBuddy via its built-in REST API that can be used to create, read, update and delete
(CRUD) products, stores and tags programmatically. This is useful if any other external applications or 
extensions want to interact with PriceBuddy.

## Authentication / API Tokens

To use any of the API endpoints, you must be authenticated. This is done via an API token. You can generate
an API token by logging into PriceBuddy, clicking the user menu (top right) and clicking "API tokens". Here
you can create a token for a specific user and assign the permissions allowed for the token.

Once a token is generated, it will appear in a notification popup. Copy this and save in a safe place, this
is essentially the same as a password and will not be shown to you again.

## API Documentation

The API documentation is generated using the application code and can be viewed via `/docs/api` url
(eg `https://my-pricebuddy.local/docs/api`).

Via the docs you can test out the API with your API token, see request and response formats and even
export the openapi json spec.
