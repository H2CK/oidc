# Nextcloud OIDC App

This is the an OIDC App for Nextcloud. This application allows to use your Nextcloud Login at other services supporting OpenID Connect.

Provided features:

- Configuration of accepted client for whom JWT Tokens are provided. Public and confidential types are supported.
- Creation of JWT Token with claims based on requested scope. (Currently supported scopes openid, profile, email, roles and groups)
- Supported signing algorithms RS256 (default) and HS256
- Group memberships are passed as roles in JWT token.
- Discovery endpoint provided
- Logout endpoint

Full documentation can be found at:

- [User Documentation](https://github.com/H2CK/oidc/wiki#user-documentation)
- [Developer Documentation](https://github.com/H2CK/oidc/wiki#developer-documentation)

## Endpoints

The following endpoint are available below `index.php/apps/oidc/`:

- Discovery: `openid-configuration` (GET)
- Authorization: `authorize`(GET)
- Token: `token`(POST)
- UserInfo: `userinfo`(GET - Authentication with previously retrieved access token)
- JWKS: `jwks`(GET)
- Logout: `logout` (GET - ?refresh_token=xxx)

CORS is enable for all domains on all the above endpoints.

The discovery endpoint should be made available at the URL: `<Issuer>/.well-known/openid-configuration`. You may have to configure your web server to redirect this url to the discovery endpoint at `<Issuer>/index.php/apps/oidc/openid-configuration`.

## Limitations

Currently it is not yet possible to use an issued JWT Token to access resource at the Nextcloud instance it self. (Future implementation planned)

Client authentication to fetch token currently only supports the sending of the client credentials in the body. Basic Auth is currently not supported.

## Development

To install it change into your Nextcloud's apps directory:

    cd nextcloud/apps

Then clone this repository.

Following install the dependencies using:

    make composer

## Frontend development

The app requires to have Node and npm installed.

- 👩‍💻 Run `make dev-setup` to install the frontend dependencies
- 🏗 To build the Javascript whenever you make changes, run `make build-js`

To continuously run the build when editing source files you can make use of the `make watch-js` command.

## Build app bundle

Execute `make build` to build for production bundle at build/artifacts. Perform `make appstore` to create tar.gz in build/artifacts.

## Execute test

Execute `make test` to run phpunit tests.

## TODOs / Ideas for extensions

- Support other methods to transport client_credentials (in query / body)
- Basic Auth support for token endpoint (Basic Auth is currently catched by Nextcloud)
- GET support for token endpoint
- Add authentication backend to allow usage of JWT to access resources at Nextcloud server
- Create unit and integration tests
