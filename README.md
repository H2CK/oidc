# Nextcloud OIDC App

This is the an OIDC App for Nextcloud. This application allows to use your Nextcloud Login at other services supporting OpenID Connect.

Provided features:

- Configuration of accepted client for whom JWT Tokens are provided
- Creation of JWT Token with claims based on requested scope. (Currently supported scopes openid, profile, email)
- Supported siging algorithms RS256 (default) and HS256
- Group memberships are passed as roles in JWT token.
- Discovery endpoint provided
- Logout endpoint

## Endpoints

The following endpoint are available below `index.php/apps/oidc/`:

- Discovery: `openid-configuration` (GET)
- Authorization: `authorize`(GET)
- Token: `token`(POST)
- UserInfo: `userinfo`(GET - Authentication with previously retrieved access token)
- JWKS: `jwks`(GET)
- Logout: `logout` (GET - ?refresh_token=xxx)

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

## TODOs

- Add selection for RS256 or HS256 algorithm at client creation
- Support public clients (no need for client secret)
- Support other methods to transport client_credentials (in query / body)
- Add button to admin UI to regenerate key material
- Add possibilty to set token expiry time in admin UI
- Add CORS Header for Client Redirect URI to endpoints jwks token authorize userinfo logout (TEST)
