# Nextcloud OIDC App

[![Release](https://img.shields.io/github/release/H2CK/oidc.svg)](https://github.com/H2CK/oidc/releases/latest)
[![Issues](https://img.shields.io/github/issues/H2CK/oidc.svg)](https://github.com/H2CK/oidc/issues)
[![License](https://img.shields.io/github/license/H2CK/oidc)](https://github.com/H2CK/oidc/blob/master/COPYING)
[![Donate](https://img.shields.io/badge/donate-PayPal-green.svg)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=QRSDVQA2UMJQC&source=url)

This is the an OIDC App for Nextcloud. This application allows to use your Nextcloud Login at other services supporting OpenID Connect.
Hint: Up to now it is not possible to use the provided Access Tokens or ID Tokens to access resources (e.g. files, calendars, ...) from your Nextcloud instance. Only the Authorization Codes can be used to fetch the Access Tokens / ID Tokens at the `/token` endpoint.

Provided features:

- Support for OpenID Connect Code (response_type = code) and Implicit (response_type = id_token) Flow - Implicite Flow must be activated per client.
- Public and confidential types of clients are supported.
- Creation of ID Token with claims based on requested scope. (Currently supported scopes openid, profile, email, roles and groups)
- Supported signing algorithms RS256 (default) and HS256
- Group memberships are passed as roles in ID token.
- Clients can be assigned to dedicated user groups. Only users in the configured group are allowed to retrieve an access token to fetch the ID token.
- Support for RFC9068 JWT Access Tokens (must be activated per client)
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

Following the supported scopes are described. If no scope is defined during the authorization request, the following scopes will be used: `openid profile email roles`. Based in the defined scope different information about the user will be provided in the id token or at the userinfo endpoint.

| Scope | Description |
|---|---|
| openid | Default scope. Will be added if missing. Information about the user is provided as user id in the claims `preferred_username` and `sub`. |
| profile | Adds the claims `name`, `family_name`, `given_name`, `middle_name`, `address`, `phone_number`, `quota` and `updated_at`to the id token. `address` and `phone_number` are only available, if those attributes are set in the users profile in Nextcloud. The claim `name` contains the display name as configured in the users profile in Nextcloud. If no display name is set the username is provided in this claim. The claims `family_name`, `given_name` and `middle_name` are generated from the display name. The generation of those claims is based on the implementation also used by the system address book of Nextcloud. The claim `quota` is only contained if a quota is set for the user. The format of the quota is provided as delivered by Nextcloud (e.g. `5 GB`) The claim `picture` contains a link to the avatar of the user provided by the Nextcloud server (format: `https://hostname/avatar/userid/size`). The picture size is limited to 64px. |
| email | Adds the email address of the user to the claim `email`. Furthermore the claim `email_verified` is added. |
| roles | Adds the groups of the user in the claim `roles`. For further details see the scope `groups`. The content of the claim `roles` is identical to the claim `groups`. |
| groups | Adds the groups of the user in the claim `groups`. The claim `groups` contains a list of the GIDs (internal Group ID) the user is assigned to. The GID might not be identical to the group name (display name) shown in the UI (especially after renaming groups or depending on your ldap configuration). To provide the display name of a group in the claim it is possible to change an application setting via the `occ` command. You can use the following commands to switch between GID and displayname: `occ config:app:set oidc group_claim_type --value "gid"` or  `occ config:app:set oidc group_claim_type --value "displayname"`. |

## Access Token & ID Token generation and validation via events by other Nextcloud apps

The app provides the events [TokenValidationRequestEvent](https://github.com/H2CK/oidc/blob/master/lib/Event/TokenValidationRequestEvent.php) (`OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent`) and [TokenGenerationRequestEvent](https://github.com/H2CK/oidc/blob/master/lib/Event/TokenGenerationRequestEvent.php) (`OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent`), which allow that other apps could request the generation of an access and id token as well as perform a validation of received access or id tokens. This way it will be possible that other Nextcloud apps could make use of access & id tokens to integrate with external services (e.g. see https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/oidc.html#generating-a-token-if-nextcloud-is-the-provider).

### Generate an Access Token and ID Token

To get a token from the oidc app, the TokenGenerationRequestEvent can be emitted. A client must have been created in advance in the settings of the oidc app.

```php
if (class_exists(OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent::class)) {
    $event = new OCA\OIDCIdentityProvider\Event\TokenGenerationRequestEvent('client_identifier', 'user_id');
    $this->eventDispatcher->dispatchTyped($event);
    $accessToken = $event->getAccessToken();
    $idToken = $event->getIdToken();
    ...
} else {
    $this->logger->debug('The oidc app is not installed/available');
}
```

### Validate an Access Token or ID Token

To validate a token by the oidc app, the TokenValidationRequestEvent can be emitted. Both an access token as well as an id token can be validated The access or ID token must have been issued by the oidc app.

```php
if (class_exists(OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent::class)) {
    $event = new OCA\OIDCIdentityProvider\Event\TokenValidationRequestEvent('token');
    $this->eventDispatcher->dispatchTyped($event);
    if ($event->getIsValid()) {
        $userId = $event-> getUserId();
        $this->logger->debug('The provided token is valid and was issued for user ' . $userId);
    } else {
        $this->logger->debug('The provided token is invalid');
    }
} else {
    $this->logger->debug('The oidc app is not installed/available');
}
```

## Expire time of tokens

The expire time of tokens (access and refresh tokens) can be set in the UI. Alternatively (especially if the provided select options in the UI do not match with your requirements) the expire times for the access and refresh token can be set using the CLI.
You could set those values with following CLI commands:

| Token Type | CLI command to set expire time |
|---|---|
| Access Token | `occ config:app:set oidc expire_time --value "123456"` |
| Refresh Token | `occ config:app:set oidc refresh_expire_time --value "123456"` |

## JWT Access Tokens (RFC9068)

It is possible to activate the use of JWT based access tokens according to RFC9068. This can be done in the settings UI or while creating a client in the CLI. If not activated an opaque access token will be generated (as it was done previously).
The generation of the JWT based access token requires in some situations that a default resource identifier is used. If there is the need
to change the predefined value you could set it via the CLI with the following command.

`occ config:app:set oidc default_resource_identifier --value "https://rs.local/"`
