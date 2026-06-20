# Nextcloud OIDC App

[![Release](https://img.shields.io/github/release/H2CK/oidc.svg)](https://github.com/H2CK/oidc/releases/latest)
[![Issues](https://img.shields.io/github/issues/H2CK/oidc.svg)](https://github.com/H2CK/oidc/issues)
[![License](https://img.shields.io/github/license/H2CK/oidc)](https://github.com/H2CK/oidc/blob/master/COPYING)
[![OIDC Compliance Test](https://img.shields.io/github/actions/workflow/status/H2CK/oidc/oidc-conformance.yaml?branch=master&label=OIDC%20Compliance%20Test)](https://github.com/H2CK/oidc/actions/workflows/oidc-conformance.yaml)
[![Donate](https://img.shields.io/badge/donate-PayPal-green.svg)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=QRSDVQA2UMJQC&source=url)

This is the an OIDC App for Nextcloud. This application allows to use your Nextcloud Login at other services supporting OpenID Connect.

Provided features:

- Support for OpenID Connect Code (response_type = code) and Implicit (response_type = id_token) Flow - Implicite Flow must be activated per client
- Support for PKCE
- Public and confidential types of clients are supported
- Creation of ID Tokens and UserInfo responses with claims based on requested scopes and the OpenID Connect `claims` parameter (currently supported scopes: openid, profile, email, roles, groups, and offline_access)
- Supported signing algorithms RS256 (default) and HS256
- Group memberships can be passed as roles or groups claims
- Clients can be assigned to dedicated user groups - Only users in the configured group are allowed to retrieve an access token to fetch the ID token
- Support for RFC9068 JWT Access Tokens (must be activated per client)
- Discovery & WebFinger endpoint provided
- Logout endpoint
- Dynamic Client Registration
- Client Configuration Management (RFC 7592)
- Token Introspection (RFC 7662)
- Support for resource url (RFC 9728) at introspection
- User Consent Management
- Support for custom claims
- Administration of clients via CLI
- Generation and validation of access tokens using events
- User specific settings to define which data is passed to clients in ID token and via userinfo endpoint

Full documentation can be found at:

- [User Documentation](https://github.com/H2CK/oidc/wiki#user-documentation)
- [Developer Documentation](https://github.com/H2CK/oidc/wiki#developer-documentation)

## Note - OIDC compliance

The OIDC conformance workflow is executed daily and on demand against the OpenID Foundation conformance suite. It currently runs the following test plans:

- `oidcc-config-certification-test-plan` for OpenID Provider discovery and metadata validation
- `oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]` for the authorization code flow with static clients
- `oidcc-hybrid-certification-test-plan[server_metadata=discovery][client_registration=static_client]` with the selected `code id_token` module set for hybrid flow coverage
- `oidcc-implicit-certification-test-plan[server_metadata=discovery][client_registration=static_client]` with the selected `id_token` module set for implicit flow coverage

More information on the compliance can be found in the [latest test run](https://github.com/H2CK/oidc/actions/workflows/oidc-conformance.yaml).

## Attention - Potential Breaking Change

Version 2.0.0 tightens several behaviours to better match the OpenID Connect conformance suite. OIDC-compliant clients should continue to work, but clients that depend on legacy 1.x behaviour should be reviewed before upgrading.

- **ID token claims in authorization code flow**: Profile, email, roles, groups, and custom claims are no longer added to the ID token only because their scopes were requested. In authorization code flow, these claims are returned by the UserInfo endpoint. If a relying party needs them directly in the ID token, it must request them explicitly with the OpenID Connect `claims` parameter, for example through `claims.id_token`.
- **Authorization code reuse**: Authorization codes are now persisted and rejected after first use. Clients must exchange each authorization code only once and must not retry the same code after a failed or timed-out token request.
- **Stricter conformance handling**: Requests using `prompt`, `max_age`, request objects, nonce-dependent response types, hybrid flow, or implicit flow are handled more strictly. Non-compliant requests that were previously accepted may now return an OIDC error response.
- **Refresh tokens**: Clients still need to request the `offline_access` scope to receive refresh tokens. For legacy clients that cannot be updated, administrators can enable "Legacy mode" in Settings > OIDC > Refresh Token Behavior.

### Migration Guide

- Check relying parties that read `email`, `preferred_username`, `groups`, `roles`, or custom claims from ID tokens. Move them to the UserInfo endpoint or add an explicit `claims.id_token` request.
- Verify that clients exchange authorization codes exactly once and handle token endpoint failures by restarting the authorization flow.
- Keep requesting `offline_access` when refresh tokens are required.
- Test clients that use implicit or hybrid flow, `prompt=none`, `prompt=login`, `max_age`, `request`, or `request_uri` against a staging upgrade.

## Installation

It is preferred to install the app via the Nextcloud App Store. If you prefer a manual installation please use the package provided in a release at Github (e.g. https://github.com/H2CK/oidc/releases/download/2.0.0/oidc-2.0.0.tar.gz).

Just cloning the git repository will provide only the source code of the application. You will not be able to use the application out of the box. 3rd party php libraries and js webpack bundles are missing and must first be generated using the commands `make install`.

## Configuration

It is possible to modify the settings of this application in Nextcloud admin settings. There is a dedicated section for the OpenID Connect provider app in the menu on the left.

In the settings you can:

- Add/Modify/Remove Clients
- Add/Modify/Remove Logout URLs
- Change some overall settings
- Regenerate your public/private key for signing the id token.

It is also possible to configure the clients and claims via the cli. The following commands are available:

```
$ php occ
...
 oidc
  oidc:create                            Create oidc client
  oidc:list                              List oidc clients
  oidc:remove                            Remove an oidc client
  oidc:create-claim                      Create a custom claim for a client
  oidc:list-claim                        List custom claims
  oidc:remove-claim                      Remove a custom claim
  oidc:list-claim-functions              Lists available functions to provide content for custom claims
...
```

Use the option `--help` to retrieve more information on how to use the commands.

### Wildcard support in Redirect Uris

Wildcards in configured redirect uris are allowed as described in the following.

- End of path wildcard support (`.../*`)
- Port wildcard for localhost (e.g. `http://localhost:*`)
- Subdomain wildcard support (e.g. `https://*.example.com/callback`) - Must be activated via `occ config:app:set oidc allow_subdomain_wildcards --value "true"` Deactivation is possible with value `false`.

### User specific settings

The administrator can give the user the right to personally select, which information is passed to the clients via the ID token and the userinfo endpoint. The following limitations are possible to define what is passed in the id token:

- Restrict passing the link to avatar picture
- Restrict passing address
- Restrict passing phone number
- Restrict passing website

Furthermore this setting activates the user consent management, so that the user has to explicitly define which scopes are allowed on first login. The consent must be renewed every 90 days.

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
- Client Configuration Management: `register/<client_id>` (PUT / GET / DELETE) - Authenticate with retrieved registration token during creation as Bearer.
- Instrospection: `introspect`(POST) - Validation of access tokens

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

Following the supported scopes are described. If no scope is defined during the authorization request, the following scopes will be used: `openid profile email roles`. Based on the defined scope different information about the user will be provided at the userinfo endpoint. For authorization code flow, profile and email scope claims are not added to the ID token unless they are explicitly requested with the OpenID Connect `claims` parameter.

Further scopes are passed transparently. Also namescaped scopes are supported. E.g. read:messages, api:admin.

| Scope | Description |
|---|---|
| openid | Default scope. Will be added if missing. The subject is provided as `sub`; `preferred_username` is returned from the userinfo endpoint and can be explicitly requested for the ID token with the `claims` parameter. |
| profile | Adds the claims `name`, `family_name`, `given_name`, `middle_name`, `address`, `phone_number`, `quota` and `updated_at` to the userinfo response. `address` and `phone_number` are only available, if those attributes are set in the users profile in Nextcloud. The claim `name` contains the display name as configured in the users profile in Nextcloud. If no display name is set the username is provided in this claim. The claims `family_name`, `given_name` and `middle_name` are generated from the display name. The generation of those claims is based on the implementation also used by the system address book of Nextcloud. The claim `quota` is only contained if a quota is set for the user. The format of the quota is provided as delivered by Nextcloud (e.g. `5 GB`) The claim `picture` contains a link to the avatar of the user provided by the Nextcloud server (format: `https://hostname/avatar/userid/size`). The picture size is limited to 64px. |
| email | Adds the email address of the user to the claim `email` in the userinfo response. Furthermore the claim `email_verified` is added. |
| groups | Adds the groups of the user in the claim `groups`. The claim `groups` contains a list of the GIDs (internal Group ID) the user is assigned to. The GID might not be identical to the group name (display name) shown in the UI (especially after renaming groups or depending on your ldap configuration). To provide the display name of a group in the claim it is possible to change an application setting via the `occ` command. You can use the following commands to switch between GID and displayname: `occ config:app:set oidc group_claim_type --value "gid"` or  `occ config:app:set oidc group_claim_type --value "displayname"`. |
| roles | Adds the groups of the user in the claim `roles`. For further details see the scope `groups`. In general the claim contains a list of group ids. If you want to explicitly set if GID or displayname is used, you can set this by: `occ config:app:set oidc role_claim_type --value "gid"` or  `occ config:app:set oidc role_claim_type --value "displayname"`. |
| offline_access | **Required for refresh tokens** (OpenID Connect Core 1.0 Section 11). When this scope is requested and granted, a refresh token will be issued that allows obtaining new access tokens even when the user is not present. If this scope is not requested, no refresh token will be issued in OIDC-compliant mode. Administrators can enable "Legacy mode" in settings to always issue refresh tokens for backward compatibility with non-compliant clients. |

### Requesting claims in the ID token

OpenID Connect scopes like `profile` and `email` request user claims for the userinfo endpoint when the authorization code flow is used. If a relying party needs specific user claims in the ID token, it must request them explicitly with the `claims` authorization request parameter. This app supports the `id_token` and `userinfo` members of the `claims` parameter.

Example authorization request parameters:

```text
response_type=code
scope=openid profile email
claims={
  "id_token": {
    "preferred_username": null,
    "email": {"essential": true},
    "email_verified": null
  }
}
```

The `claims` value must be sent as URL-encoded JSON in the authorization request. Claim request values may be `null` or a JSON object; `value` and `values` qualifiers are honored for explicitly requested optional claims. If a requested claim is unavailable, not released by the user, or disabled by policy, it can be omitted from the ID token.

## Custom claims

It is possible to define custom claims per client. A custom claim is defined per client and will be added to the userinfo endpoint if the specified scope is requested. For authorization code flow, a custom claim is added to the ID token only when its claim name is also explicitly requested with `claims.id_token`. The following functions can be used to provide data to the custom claims.
| Function | Description |
|---|---|
| isAdmin | Provides true or false (boolean) if the user is Nextcloud administrator. |
| isGroupAdmin | A single parameter must be provided which contains the Nextcloud group id (not the display name). Provides true or false (boolean) if the user is a subadmin (group admin) of the specified group, or null if the group does not exist. In case the group does not exist, the claim is not added to the ID token or userinfo endpoint. |
| hasRole | A single parameter must be provided which contains the Nextcloud group id (not the display name) against which the check is performed. Provides true or false (boolean) if the user is in the specified group. |
| isInGroup | Same as `hasRole` |
| getUserEmail | Returns the users primary email address as string |
| getUserGroups | Returns the groups of the user as string[] |
| getUserGroupsDisplayName | Returns the display name of the groups of the user as string[] |
| getUserLanguage | Returns the language, that is used by the user or forced by system |
| getUserLocale | Returns the locale, that is used by the user or forced by system |
| getUserFWOD | Return the Users setting of first day of week or use the locale setting (0 = sunday, 1 = monday, ...) |
| getUserTimezone | Return the Users setting of timezone or or forced by system |

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

## Use of none auto-generated ClientId and ClientSecret

It is possible to created new clients where the client id and client secret is not auto generated by this app. This is not possible when using the UI. If you want to use this functionality you have to ensure that you use the API directly or use the CLI create command to pass those two attributes in the request or the command line. When using self generated client id and client secrets please ensure the following:

- Both attributes must only contain characters as defined in RFC 6749 (OAuth 2.0) Appendix A https://datatracker.ietf.org/doc/html/rfc6749#appendix-A which defines both as *VSCHAR (any printable ASCII, %x20-7E)
- Minimum length is 32 characters (for security reasons you should use the maximum length)
- Maximum length is 64 characters
- The client id must be unique

## Expire time of tokens

The expire time of tokens (access and refresh tokens) can be set in the UI. Alternatively (especially if the provided select options in the UI do not match with your requirements) the expire times for the access and refresh token can be set using the CLI.
You could set those values with following CLI commands:

| Token Type | CLI command to set expire time |
|---|---|
| Access Token | `occ config:app:set oidc expire_time --value "123456"` |
| Refresh Token | `occ config:app:set oidc refresh_expire_time --value "123456"` |

## JWT Access Tokens (RFC9068)

It is possible to activate the use of JWT based access tokens according to RFC9068. This can be done in the settings UI or while creating a client in the CLI. If not activated an opaque access token will be generated (as it was done previously).
