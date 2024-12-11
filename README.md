# Nextcloud OIDC App

[![Release](https://img.shields.io/github/release/H2CK/oidc.svg)](https://github.com/H2CK/oidc/releases/latest)
[![Issues](https://img.shields.io/github/issues/H2CK/oidc.svg)](https://github.com/H2CK/oidc/issues)
[![License](https://img.shields.io/github/license/H2CK/oidc)](https://github.com/H2CK/oidc/blob/master/COPYING)
[![Donate](https://img.shields.io/badge/donate-PayPal-green.svg)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=QRSDVQA2UMJQC&source=url)

This is the an OIDC App for Nextcloud. This application allows to use your Nextcloud Login at other services supporting OpenID Connect.
Hint: Up to now it is not possible to use the provided Access Tokens or ID Tokens to access resources (e.g. files, calendars, ...) from your Nextcloud instance. Only the Authorization Codes can be used to fetch the Access Tokens / ID Tokens at the `/token` endpoint.

Provided features:

- Support for OpenID Connect Code (response_type = code) and Implicit (response_type = id_token) Flow (since version 0.4.0) - Implicite Flow must be activated per client.
- Configuration of accepted client for whom JWT Tokens are provided. Public and confidential types are supported.
- Creation of JWT Token with claims based on requested scope. (Currently supported scopes openid, profile, email, roles and groups)
- Supported signing algorithms RS256 (default) and HS256
- Group memberships are passed as roles in JWT token.
- Clients can be assigned to dedicated user groups. Only users in the configured group are allowed to retrieve an access token to fetch the JWT.
- Discovery & WebFinger endpoint provided
- Logout endpoint
- Dynamic Client Registration
- Administration of clients via CLI

Full documentation can be found at:

- [User Documentation](https://github.com/H2CK/oidc/wiki#user-documentation)
- [Developer Documentation](https://github.com/H2CK/oidc/wiki#developer-documentation)

## Configuration

It is possible to modify the settings of this application in Nextcloud admin settings in the section security. There you find the an area with the headline 'OpenID Connect clients'.

In this area you can:

- Add/Modify/Remove Clients
- Add/Modify/Remove Logout URLs
- Change some overall settings
- Regenerate your public/private key for signeing the id token.

It is also possible to configure the clients via the cli. The following commands are available:

```
$ php occ
...
 oidc
  oidc:create                            Create oidc client
  oidc:list                              List oidc clients
  oidc:remove                            Remove an oidc client
...
```

Use the option `--help` to retrieve more information on how to use the commands.

## Endpoints

The following endpoint are available below `index.php/apps/oidc/`:

- Discovery: `openid-configuration` (GET) or at `index.php/.well-known/openid-configuration`
- WebFinger: at `index.php/.well-known/webfinger`
- Authorization: `authorize`(GET)
- Token: `token`(POST) - Credentials for authentication can be passed via Authorization header or in body. (Ususally the Authorization header is fetched directly by the Nextcloud server itself and is not passed to the oidc application. To allow the use of this mechanism a pseudo user backend is provided. Nevertheless this causes an exception shown in the logs on each login using the Authorization header.)
- UserInfo: `userinfo`(GET / POST - Authentication with previously retrieved access token)
- JWKS: `jwks`(GET)
- Logout: `logout` (GET)
- Dynamic Client Registration: `register` (POST) - Disabled by default. Must be enabled in settings.

CORS is enabled for all domains on all the above endpoints. Except the webfinger endpoint for which the CORS settings cannot be controlled by the oidc app.

The discovery and web finger endpoint should be made available at the URL: `<Issuer>/.well-known/openid-configuration`. You may have to configure your web server to redirect this url to the discovery endpoint at `<Issuer>/index.php/apps/oidc/openid-configuration` (or `<Issuer>/index.php/.well-known/openid-configuration`). For web finger there should be a redirect to `<Issuer>/index.php/.well-known/webfinger`.

### Logout Details

To support logout functionality the discovery enpoint contains the attribute `end_session_endpoint`to announce the support for [RP-Initiated logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html). The current implementation only partially supports this specification.

Current limitations:

- Only GET requests to logout endpoint are supported (POST might be added in future)
- Only the optional attributes `id_token_hint`, `client_id` and `post_logout_redirect_uri` are supported

Remark on `post_logout_redirect_uri`: The passed URIs are checked against the list of allowed logout redirect URIs from the app configuration. The provided `post_logout_redirect_uri` must start with one of the configured URIs.
If no `post_logout_redirect_uri` is passed or the `post_logout_redirect_uri` does not match any allowed redirect URI, there will be a redirect to the login page of the Nextcloud instance.

Up to now there is NO support for:

- [OpenID Connect Session Management](https://openid.net/specs/openid-connect-session-1_0.html)
- [OpenID Connect Front-Channel Logout](https://openid.net/specs/openid-connect-frontchannel-1_0.html)
- [OpenID Connect Back-Channel Logout](https://openid.net/specs/openid-connect-backchannel-1_0.html)

### Dynamic Client Registration Details

It is possible to use the dynamic client registration according to [OpenID Connect Dynamic Client Registration 1.0](https://openid.net/specs/openid-connect-registration-1_0.html). To use this feature you have to enable it in the settings of this application (see above).

Due to security reasons there is a BruteForce throttleing as well as a limitation of dynamically registered clients to 100. Additionally a dynamically registered client is only valid for 3600 seconds. Both parameters can currently not be changed via the settings.
The registration endpoint is accessible for everybody without any authentication and authorization. So please enable this feature with the possible thread in mind.

## Scopes

| Scope | Description |
|---|---|
| openid | Default scope. Will be added if missing. Information about the user is provided as user id in the claims `preferred_username` and `sub`. |
| profile | Adds the claims `name`, `address`, `phone_number`, `quota` and `updated_at`to the ID Token. `address` and `phone_number` are only available, if those attributes are set in the users profile in Nextcloud. The claim `name` contains the display name as configured in the users profile in Nextcloud. If no display name is set the username is provided in this claim. The claim `quota` is only contained if a quota is set for the user. The format of the quota is provided as delivered by Nextcloud (e.g. `5 GB`) If enabled in the app settings it is also possible to generate the claim `picture` with users avatar encoded in a data url. Caution: If enabled for the id token this might lead to very large id token, even if the picture size is limited to 64px. |
| email | Adds the email address of the user to the claim `email`. Furthermore the claim `email_verified` is added. |
| roles | Adds the groups of the user in the claim `roles`. |
| groups | Adds the groups of the user in the claim `groups`. |

## Limitations

Currently it is not yet possible to use an issued Access Token or ID Token to access resources at the Nextcloud instance it self.

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

## Translations

Translations are done using Transifex. If you like to contribute and do some translations please visit [Transifex](https://www.transifex.com/nextcloud/nextcloud/oidc/).

Before using Transifex, translations were made with a local translation tool. For installation of the necessary tools execute `make translationtool`. To create the pot file from the source code execute `make generate-po-translation`. After creating the po translation files under translationfiles/...LANGUAGE-CODE.../oidc.po you must execute `make generate-nc-translation` to generate the necessary nextcloud translation files.

## Build app bundle

Execute `make build` to build for production bundle at build/artifacts. Perform `make appstore` to create tar.gz in build/artifacts.

### Releasing

To create a new release the following files must be modified and contain the new version.

- appinfo/info.xml
- package.json
- CHANGELOG.md

## Execute test

Execute `make test` to run phpunit tests.

### Manual testing of BackgroundJobs

Execute  `php -dxdebug.remote_host=localhost -f cron.php`

To run the job again if you have errors, however, you may have to remove it from the oc_jobs table and disable/reenable the app.

## TODOs / Ideas for extensions

- Support other methods to transport client_credentials (in query / body)
- GET support for token endpoint
- Add authentication backend to allow usage of JWT to access resources at Nextcloud server
- Create unit and integration tests
