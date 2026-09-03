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
- Support for OAuth 2.0 Token Exchange (RFC 8693) using a constrained access-token-to-access-token profile
- Discovery & WebFinger endpoint provided
- RP-Initiated Logout, OpenID Connect Front-Channel Logout 1.0, Back-Channel Logout 1.0, and Session Management 1.0
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

The OIDC conformance workflow is executed daily and on demand against the OpenID Foundation conformance suite. For reproducible CI results, the workflow pins the suite to the fixed upstream release `release-v5.1.43` instead of building the moving `master` branch. It currently runs the following test plans:

- `oidcc-config-certification-test-plan` for OpenID Provider discovery and metadata validation
- `oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]` and `oidcc-formpost-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]` for authorization code flow
- `oidcc-hybrid-certification-test-plan[server_metadata=discovery][client_registration=static_client]` and `oidcc-formpost-hybrid-certification-test-plan[server_metadata=discovery][client_registration=static_client]` with `code id_token` response type, testing modules: server, userinfo (GET/POST), nonce enforcement, scope handling (profile, email, address, phone), prompt parameters (login, none), max_age variations, code reuse, PKCE, refresh tokens, claims essential, redirect URI validation, request object support/rejection, and form post
- `oidcc-implicit-certification-test-plan[server_metadata=discovery][client_registration=static_client]` and `oidcc-formpost-implicit-certification-test-plan[server_metadata=discovery][client_registration=static_client]` with `id_token` response type, testing modules: server, nonce enforcement, scope handling (profile, email, address, phone), prompt parameters (login, none), max_age variations, redirect URI validation, request object support/rejection, claims essential, and form post
- `oidcc-rp-initiated-logout-certification-test-plan[response_type=code id_token][client_registration=static_client]` for RP-Initiated Logout, including valid logout flows, state handling, ID token hint validation, and post-logout redirect URI validation
- `oidcc-backchannel-rp-initiated-logout-certification-test-plan[response_type=code id_token][client_registration=static_client]` for OpenID Connect Back-Channel Logout, including `sid`-based RP session correlation and signed Logout Token delivery
- `oidcc-frontchannel-rp-initiated-logout-certification-test-plan[response_type=code id_token][client_registration=static_client]` for OpenID Connect Front-Channel Logout
- `oidcc-session-management-certification-test-plan[response_type=code id_token][client_registration=static_client]` for OpenID Connect Session Management and `check_session_iframe` behavior

More information on the compliance can be found in the [latest test run](https://github.com/H2CK/oidc/actions/workflows/oidc-conformance.yaml).

## Attention - Potential Breaking Change

### Version 2.3.0

Version 2.3.0 adds OpenID Connect Front-Channel Logout 1.0 and OpenID Connect Session Management 1.0. The database migration adds optional per-client Front-Channel Logout metadata only; existing clients remain valid and Front-Channel Logout is disabled for a client until a `frontchannel_logout_uri` is configured. Session Management is a browser feature: HTTP(S) authorization responses include `session_state` when the OP itself is served over HTTPS. Native/custom-scheme redirect URIs keep their existing behavior and intentionally do not receive `session_state`, because they have no browser web origin that can host the Session Management RP iframe.

### Version 2.2.0

Version 2.2.0 hardens RP-Initiated Logout and Back-Channel Logout session handling. The 2.2.0 upgrade migration deliberately invalidates persisted OIDC authorization codes and access/refresh grant state. Existing relying parties therefore cannot continue with pre-upgrade refresh tokens and must start a new OIDC authorization/login flow after the upgrade. This one-time reauthentication is intentional so that newly issued ID Tokens and RP sessions are correlated with the current `sid` state.

The same migration extends accepted post-logout redirect URIs with an optional RP binding. Existing logout redirect URI rows remain global (`client_id = NULL`) and keep their historical behavior. An RP-specific list takes precedence when at least one URI is configured for that RP; the global list is used only when that RP has no RP-specific post-logout redirect URI configured.

Version 2.0.0 tightens several behaviours to better match the OpenID Connect conformance suite. OIDC-compliant clients should continue to work, but clients that depend on legacy 1.x behaviour should be reviewed before upgrading.

- **ID token claims in authorization code flow**: Profile, email, roles, groups, and custom claims are no longer added to the ID token only because their scopes were requested. In authorization code flow, these claims are returned by the UserInfo endpoint. If a relying party needs them directly in the ID token, it must request them explicitly with the OpenID Connect `claims` parameter, for example through `claims.id_token`. The `claims` parameter is part of the authorization request to `/index.php/apps/oidc/authorize`. It can be sent either in the authorization request URL for `GET` requests or in the form body for `POST` authorization requests. The value is a JSON object and must be URL-encoded when sent in the request URL or as form data.

  Example authorization request parameters for adding `roles` and `preferred_username` to the ID token:

  ```text
  response_type=code
  scope=openid profile roles
  claims={
    "id_token": {
      "roles": null,
      "preferred_username": null
    }
  }
  ```

  Example encoded authorization URL:

  ```text
  /index.php/apps/oidc/authorize?response_type=code&client_id=my-client&redirect_uri=https%3A%2F%2Fclient.example%2Fcallback&scope=openid%20profile%20roles&state=abc&claims=%7B%22id_token%22%3A%7B%22roles%22%3Anull%2C%22preferred_username%22%3Anull%7D%7D
  ```

  The previous behavior (of version 1.x) can be activated in UI under Settings > OIDC > Always Include Scope Claims or by using the occ command line.
- **Authorization code reuse**: Authorization codes are now persisted and rejected after first use. Clients must exchange each authorization code only once and must not retry the same code after a failed or timed-out token request.
- **Stricter conformance handling**: Requests using `prompt`, `max_age`, request objects, nonce-dependent response types, hybrid flow, or implicit flow are handled more strictly. Non-compliant requests that were previously accepted may now return an OIDC error response.
- **Refresh tokens**: Clients still need to request the `offline_access` scope to receive refresh tokens. For legacy clients that cannot be updated, administrators can enable "Legacy mode" in Settings > OIDC > Refresh Token Behavior.

### Migration Guide

- Check relying parties that read `email`, `preferred_username`, `groups`, `roles`, or custom claims from ID tokens. Move them to the UserInfo endpoint or add an explicit `claims.id_token` request.
- Verify that clients exchange authorization codes exactly once and handle token endpoint failures by restarting the authorization flow.
- Keep requesting `offline_access` when refresh tokens are required.
- Test clients that use implicit or hybrid flow, `prompt=none`, `prompt=login`, `max_age`, `request`, or `request_uri` against a staging upgrade.

## Installation

It is preferred to install the app via the Nextcloud App Store. If you prefer a manual installation please use the package provided in the [latest GitHub release](https://github.com/H2CK/oidc/releases/latest).

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
  oidc:create-logout-redirect-uri        Create an accepted OIDC logout redirect URI
  oidc:list-logout-redirect-uri          List accepted OIDC logout redirect URIs
  oidc:remove-logout-redirect-uri        Remove an accepted OIDC logout redirect URI
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
- Token: `token`(POST) - Credentials for authentication can be passed via Authorization header (client_secret_basic) or in body (client_secret_post).
- UserInfo: `userinfo`(GET / POST - Authentication with previously retrieved access token)
- JWKS: `jwks`(GET)
- Logout: `logout` (GET / POST)
- Session Management OP iframe: `session/check-session-iframe` (GET; advertised as `check_session_iframe`)
- Dynamic Client Registration: `register` (POST) - Disabled by default. Must be enabled in settings.
- Client Configuration Management: `register/<client_id>` (PUT / GET / DELETE) - Authenticate with retrieved registration token during creation as Bearer.
- Instrospection: `introspect`(POST) - Validation of access tokens

CORS is enabled for all domains on all the above endpoints. Except the webfinger endpoint for which the CORS settings cannot be controlled by the oidc app.

The discovery and web finger endpoint should be made available at the URL: `<Issuer>/.well-known/openid-configuration`. You may have to configure your web server to redirect this url to the discovery endpoint at `<Issuer>/index.php/apps/oidc/openid-configuration` (or `<Issuer>/index.php/.well-known/openid-configuration`). For web finger there should be a redirect to `<Issuer>/index.php/.well-known/webfinger`.

### Logout Details

The discovery document advertises `end_session_endpoint` to signal support for [RP-Initiated Logout](https://openid.net/specs/openid-connect-rpinitiated-1_0.html). The endpoint accepts both `GET` and `POST` requests and supports the optional `id_token_hint`, `client_id`, `post_logout_redirect_uri`, and `state` parameters.

An active Nextcloud session is terminated without additional interaction only when a cryptographically valid `id_token_hint` identifies the current user, RP, and `sid` registered for that browser session. The hint is verified with the ID-token signing algorithm configured for that RP (`RS256` or `HS256`); algorithm mismatches are rejected and HS256 validation fails closed if the RP has no usable client secret. When a real OP logout occurs, the provider stores the `(user, client, sid)` correlation for 10 minutes as short-lived recent-session history. An expired but otherwise valid ID Token hint can therefore still be accepted when its RP/user/`sid` matches either the current OP/RP browser session or a session that this OP actually logged out within that recent-session window. Reauthentication caused by `prompt=login` or `max_age` is deliberately not recorded as a recent logout. If an active OP session exists but the hint is missing, invalid, belongs to another session, or contains a stale/pre-upgrade `sid`, the endpoint shows an explicit logout confirmation page instead of terminating the session. The confirmation page is rendered as a Nextcloud `TemplateResponse` using the guest layout, so it follows the active Nextcloud theme and branding. All visible confirmation texts use Nextcloud's localization mechanism and can be translated in the same way as other app strings. The confirmation uses a short-lived, one-time token bound to the current Nextcloud session.

RP-Initiated Logout terminates browser/session state and triggers registered logout notifications; it is **not** a global OAuth grant-revocation endpoint. In particular, it does not delete all persisted access/refresh grants of the user and therefore does not implicitly revoke unrelated clients or `offline_access` grants. Token/grant revocation remains the responsibility of the existing token lifecycle/revocation mechanisms. If no active OP session exists, a hint is accepted only when it can be correlated to a recent OP/RP session; otherwise it is rejected.

For security, a `post_logout_redirect_uri` is used only when its legitimacy is established in one of these ways:

- A valid `id_token_hint` identifies the initiating RP and the URI is an exact match for that RP's effective post-logout redirect URI allow-list; or
- no `id_token_hint` is supplied, but a valid `client_id` identifies the RP and the URI exactly matches that RP's effective allow-list. If an OP session is active, the End-User must explicitly confirm logout; the validated RP/redirect/state context is stored server-side in the one-time confirmation state and revalidated immediately before use. If the End-User is already logged out, the same client-bound exact-match check can be used directly because there is no OP session left to terminate.

When an invalid `id_token_hint` was supplied, the OP may still offer the End-User a local logout confirmation, but it does not use the failed hint to authorize post-logout redirection.

RP-specific post-logout redirect URIs can be configured in **Administration settings > OIDC > Edit client > Post Logout Redirect URIs**. If at least one RP-specific URI exists, only those URIs are accepted for that RP. If the RP has no RP-specific entries, the legacy **Global Accepted Logout Redirect URIs** list is used as a backward-compatible fallback. Existing global entries therefore continue to work after upgrading to 2.2.0.

> **Security note about the global fallback:** a global post-logout redirect URI is intentionally trusted for every RP that has no RP-specific post-logout redirect configuration. This is less isolated than client-bound registration and exists only for backward compatibility. Administrators should prefer RP-specific entries (through UI, OCC, or DCR) and keep the global list limited to explicitly trusted legacy targets. As soon as one RP-specific URI is configured for a client, the global list is no longer considered for that client.

The same distinction is available through OCC. Omitting `--client-id` continues to operate on the global list:

```bash
# RP-specific
occ oidc:create-logout-redirect-uri https://rp.example.com/logout/callback --client-id rp-client-id
occ oidc:list-logout-redirect-uri --client-id rp-client-id
occ oidc:remove-logout-redirect-uri https://rp.example.com/logout/callback --client-id rp-client-id

# Legacy global fallback
occ oidc:create-logout-redirect-uri https://legacy.example.com/logout/callback
occ oidc:list-logout-redirect-uri
occ oidc:remove-logout-redirect-uri https://legacy.example.com/logout/callback
```

When accepted, `state` is appended to the redirect URI. Without an accepted post-logout redirect URI, the user is redirected to the Nextcloud login page.

OIDC reauthentication requests are intentionally different from logout. `prompt=login` and an exceeded `max_age` force the user through a fresh Nextcloud authentication flow, but they do not send Back-Channel Logout notifications, revoke other RP grants, or discard the existing RP-to-`sid` correlations. A real Nextcloud/RP-Initiated Logout continues to notify all participating RPs normally.

### Front-Channel Logout

The provider supports [OpenID Connect Front-Channel Logout 1.0](https://openid.net/specs/openid-connect-frontchannel-1_0.html). Discovery advertises `frontchannel_logout_supported=true` and `frontchannel_logout_session_supported=true`. The same stable per-RP `sid` used for Back-Channel Logout is included in ID Tokens and is also used for session-specific Front-Channel Logout.

When an actual OP logout is performed through the OIDC logout endpoint, the provider takes a snapshot of all RPs participating in the current browser session before the Nextcloud session is destroyed. It then renders the configured Front-Channel Logout URIs in hidden iframes and continues to the normal post-logout destination. When a Front-Channel Logout URI is called, the provider includes both `iss` and `sid`; this is also done when `frontchannel_logout_session_required` is `false`, because this OP supports session-specific logout.

The same browser notification is also applied to normal Nextcloud logouts. The app detects the logout through Nextcloud's public `BeforeUserLoggedOutEvent`, snapshots the RP sessions before they are cleared, and passes the Front-Channel targets through a request-local context to a global response middleware. The middleware only replaces an existing post-logout redirect when browser fan-out is required; it does not depend on an internal `OC\\Core` controller class. OIDC reauthentication caused by `prompt=login` or `max_age` is explicitly excluded from this logout context and therefore does not trigger Front-Channel Logout.

#### Configure Front-Channel Logout in the Admin UI

1. Open **Administration settings > OIDC** and edit the client.
2. Expand **Further Settings**.
3. Set **Front-Channel Logout URI** to the browser endpoint at the RP that clears the RP session when loaded in an iframe.
4. Enable **Require iss and sid in Front-Channel Logout requests** if the RP registers `frontchannel_logout_session_required=true`.

Use an absolute HTTPS URI without a fragment. HTTP is accepted only for confidential clients. The URI may contain its own query parameters; the provider retains them when it appends `iss` and `sid`. As required by OpenID Connect Front-Channel Logout 1.0, the Front-Channel Logout URI must use the same scheme, host, and effective port as at least one registered authorization redirect URI. Default HTTP/HTTPS ports are normalized according to normal origin serialization rules (for example, `https://rp.example:443/callback` and `https://rp.example/frontchannel-logout` have the same origin).

The same settings can be supplied when creating a static client with OCC:

```bash
occ oidc:create "Example RP" https://rp.example.com/oidc/callback \
  --frontchannel_logout_uri https://rp.example.com/oidc/frontchannel-logout \
  --frontchannel_logout_session_required
```

For Dynamic Client Registration and RFC 7592 Client Configuration Management, use the standard metadata members:

```json
{
  "frontchannel_logout_uri": "https://rp.example.com/oidc/frontchannel-logout",
  "frontchannel_logout_session_required": true
}
```

`frontchannel_logout_session_required=true` is rejected if no Front-Channel Logout URI is configured.

### Session Management

The provider supports [OpenID Connect Session Management 1.0](https://openid.net/specs/openid-connect-session-1_0.html) for browser-based HTTP(S) relying parties. When the OP is served over HTTPS, Discovery contains `check_session_iframe`, and browser-based HTTP(S) Authentication Responses contain the required opaque `session_state` parameter. Native/custom-scheme redirect URIs are outside this browser-session profile and intentionally do not receive `session_state`. The value is bound to the client identifier, the concrete registered RP origin, the current OP browser state, and a random salt. It also carries an OP-signed RS256 client/origin binding so the iframe can reject unregistered or forged source origins locally without a network request for every status check. It never contains a space.

An RP can embed the advertised `check_session_iframe` in a hidden iframe and send the standard message:

```text
<client_id> <session_state>
```

The iframe returns `unchanged`, `changed`, or `error` with `postMessage`. Before calculating session status, it requires the source origin to match the origin embedded in `session_state` and verifies the OP's RS256 signature over the supplied `client_id` and that origin. The OP only creates this signed binding after the concrete authorization redirect URI has matched the client's registered redirect URI configuration, including supported wildcard patterns. A forged or unexpected client/origin combination therefore returns `error` instead of being treated as a normal session change. The OP browser state changes on login/logout/user changes and when a previously non-participating RP is added to the current OP browser session, so existing RPs subsequently observe `changed`.

No per-client switch is required for Session Management. RPs that do not use the feature can ignore both `check_session_iframe` and `session_state`. Session Management is intentionally limited to browser-based HTTP(S) RPs; custom-scheme/native redirect URIs are not given `session_state`, preserving compatibility for non-browser clients.

### Back-Channel Logout

The provider supports [OpenID Connect Back-Channel Logout 1.0](https://openid.net/specs/openid-connect-backchannel-1_0.html). Discovery advertises both `backchannel_logout_supported` and `backchannel_logout_session_supported` as `true`.

For OIDC authorizations performed after this feature is installed, the provider creates a stable `sid` for each relying party (RP) participating in the current Nextcloud browser session. The same `sid` is included in ID tokens issued for that RP. When the Nextcloud session is logged out, including logout initiated through the RP-Initiated Logout endpoint, the provider sends a signed Logout Token to every participating RP that has a `backchannel_logout_uri` configured.

The Logout Token:

- is sent with HTTP `POST` as `application/x-www-form-urlencoded` in the `logout_token` parameter;
- is signed with the client's configured ID-token signing algorithm (`RS256` or `HS256`);
- uses the JOSE header `typ=logout+jwt`;
- contains `iss`, `sub`, `aud`, `iat`, `exp`, `jti`, `sid`, and the Back-Channel Logout `events` claim and does not contain `nonce`;
- expires 120 seconds after issuance.

The RP endpoint should validate the signature and the Logout Token claims, especially `iss`, `aud`, `iat`/`exp`, `jti`, `events`, and `sid`, terminate the corresponding RP session, and return HTTP `200` or `204`. Back-Channel Logout requests for all participating RPs are started asynchronously before their results are awaited, so one slow RP does not serially delay requests to the remaining RPs. A failing or temporarily unavailable RP does not prevent the local Nextcloud logout or notifications to other RPs. HTTP 408, HTTP 429, HTTP 5xx responses, transport failures, and failures while starting the HTTP request are treated as potentially recoverable: the provider queues at most two retries with minimum delays of 30 seconds and 120 seconds. HTTP status errors are returned to the application as normal responses (`http_errors=false`) so permanent HTTP 4xx responses such as 400, 401, 403, and 404 are not accidentally classified as transport failures and are not retried. Each retry re-reads the current client configuration and generates a fresh, `sid`-correlated Logout Token; queued retry arguments intentionally do not retain the user ID. Actual execution time depends on the configured Nextcloud background-job runner.

#### Configure Back-Channel Logout in the Admin UI

1. Open **Administration settings > OIDC** and edit the client.
2. Expand **Further Settings**.
3. Set **Back-Channel Logout URI** to the RP endpoint that accepts Back-Channel Logout Tokens.
4. Enable **Require sid in Back-Channel Logout Tokens** if the RP registers `backchannel_logout_session_required=true`.

Use an absolute HTTPS URI. For statically/admin-configured confidential clients, HTTP remains accepted for backward compatibility with the base Back-Channel Logout policy; dynamically registered clients (DCR and RFC 7592 updates) must always use HTTPS. Fragments and embedded user credentials are rejected. For dynamically registered clients, an additional application-level SSRF policy applies independently of Nextcloud's global `allow_local_remote_servers` setting: loopback, RFC1918/private, link-local, IPv6 ULA, shared-address-space, reserved/non-publicly-routable addresses, and known cloud metadata endpoints are rejected. Hostnames must resolve successfully and every resolved IPv4/IPv6 address must be publicly routable. The policy is checked during DCR/RFC 7592 updates and again immediately before every initial Back-Channel Logout delivery and retry. For DCR callbacks the request also explicitly sets Nextcloud's per-request `allow_local_address` option to `false`, forcing its DNS-pinning/local-address protection even when `allow_local_remote_servers` is globally enabled; this additionally pins the validated DNS result for the actual HTTP connection and reduces DNS-rebinding exposure.

The `backchannel_logout_session_required` option represents the standard client metadata value. This provider has session support and includes `sid` in Logout Tokens whenever an RP session has been registered, irrespective of whether the RP marks the claim as required.

The same settings can be configured when creating a static client with `occ oidc:create`:

```bash
occ oidc:create "Example RP" https://rp.example.com/oidc/callback \
  --backchannel_logout_uri https://rp.example.com/oidc/backchannel-logout \
  --backchannel_logout_session_required
```

Omit `--backchannel_logout_session_required` when the RP supports Back-Channel Logout but does not require session-specific `sid` correlation. Back-Channel Logout itself is enabled by configuring `backchannel_logout_uri`; the session-required flag is not an enable/disable switch.

#### Dynamic Client Registration and Client Configuration

The standard metadata can also be supplied through Dynamic Client Registration and changed through RFC 7592 Client Configuration Management:

```json
{
  "client_name": "Example RP",
  "redirect_uris": ["https://rp.example.com/oidc/callback"],
  "post_logout_redirect_uris": ["https://rp.example.com/logout/callback"],
  "backchannel_logout_uri": "https://rp.example.com/oidc/backchannel-logout",
  "backchannel_logout_session_required": true,
  "frontchannel_logout_uri": "https://rp.example.com/oidc/frontchannel-logout",
  "frontchannel_logout_session_required": true
}
```

`backchannel_logout_session_required=true` is rejected if no Back-Channel Logout URI is configured. `frontchannel_logout_session_required=true` likewise requires `frontchannel_logout_uri`. The same URI validation rules as in the Admin UI apply. Dynamic registration and RFC 7592 also support the RP-Initiated Logout `post_logout_redirect_uris` metadata member. These values are stored as RP-specific entries and are returned by the registration/configuration endpoints. Matching at logout time is exact; wildcards, fragments, embedded credentials, and malformed/non-absolute values are rejected. HTTPS is recommended; HTTP is accepted only for confidential clients, and custom URI schemes remain possible for native-style callbacks. The active or local schemes `javascript:`, `data:`, `file:`, and `vbscript:` are explicitly rejected (case-insensitively) and cannot be registered through DCR or RFC 7592. On RFC 7592 update, the JSON payload must include `client_id`, and it must exactly match the currently issued client identifier. If `client_secret` is included, it must exactly match the currently issued secret; the update endpoint never accepts a caller-chosen replacement secret. Omitting `post_logout_redirect_uris` leaves the current RP-specific list unchanged, while an explicit empty array removes the RP-specific list and therefore re-enables the documented legacy global fallback for that RP.

Dynamic registration and RFC 7592 updates accept only `RS256` and `HS256` for `id_token_signed_response_alg`; unsupported algorithms are rejected with `invalid_client_metadata`. Token generation also fails closed if an unsupported algorithm is nevertheless found in persisted client state.

> **Version 2.2.0 upgrade note:** Upgrading to 2.2.0 deliberately invalidates all existing persisted OIDC authorization-code and access/refresh grant state. Existing RPs must start a new OIDC authorization/login flow; pre-upgrade refresh tokens can no longer be used. This one-time reauthentication is required to establish fresh security state after the Back-Channel/RP-Initiated Logout hardening. Existing accepted logout redirect URIs are preserved as global fallback entries, while new RP-specific `post_logout_redirect_uri` entries can be configured per client. The upgrade also adds the recent-session table used for RP-Initiated Logout correlation; it contains only user ID, client identifier, `sid`, and logout time and is not an OAuth grant store. Entries are accepted only for 10 minutes and older rows are opportunistically cleaned during later logout processing. The in-browser Back-Channel Logout session key remains versioned so a pre-upgrade `sid` is not silently reused. Already issued self-contained ID Tokens may remain cryptographically valid until their `exp`, but they cannot silently terminate an active OP session unless their `sid` matches current session state; after logout, expired hints are accepted only within the 10-minute recent-session window.

### Dynamic Client Registration Details

It is possible to use the dynamic client registration according to [OpenID Connect Dynamic Client Registration 1.0](https://openid.net/specs/openid-connect-registration-1_0.html). To use this feature you have to enable it in the settings of this application (see above).

Back-Channel Logout metadata (`backchannel_logout_uri` and `backchannel_logout_session_required`), Front-Channel Logout metadata (`frontchannel_logout_uri` and `frontchannel_logout_session_required`), and RP-Initiated Logout metadata (`post_logout_redirect_uris`) are accepted during registration and are returned/updated by RFC 7592 Client Configuration Management. See the Logout sections above for URI restrictions, the global-fallback compatibility rule, and examples.

Due to security reasons there is a BruteForce throttleing as well as a limitation of dynamically registered clients to 100. Additionally a dynamically registered client is only valid for 3600 seconds. Both parameters can currently not be changed via the settings.
The registration endpoint is accessible for everybody without any authentication and authorization. So please enable this feature with the possible thread in mind.

Dynamically registered clients are **not automatically authorized for Token Exchange**. RFC 8693 defines Token Exchange as a token-endpoint extension grant but does not define client-registration metadata for the administrative trust relationship between a requesting client and the clients from which it may accept subject tokens. RFC 7591 permits extension grant-type values and additional registration metadata, but it does not require an authorization server to let a dynamically registering client grant itself Token Exchange trust privileges. In this implementation a DCR-created client therefore starts with Token Exchange disabled and cannot enable it or configure allowed subject-token clients through DCR/RFC 7592. An administrator must explicitly enable Token Exchange, select at least one allowed subject client, and configure at least one allowed Token Exchange scope in the Admin UI. This is an intentional security policy, not a limitation imposed by RFC 8693.

## Token Exchange (RFC 8693)

The OIDC app supports [OAuth 2.0 Token Exchange (RFC 8693)](https://www.rfc-editor.org/rfc/rfc8693.html) at the normal token endpoint. The implementation intentionally provides a constrained profile focused on exchanging an access token issued by this OIDC provider for another access token that is suitable for a configured backend resource.

### Typical use cases

Token Exchange is useful when a confidential application or backend receives a user access token but should not forward that token unchanged to another service. Typical scenarios are:

- **Backend-for-Frontend (BFF):** A backend receives a user's access token and exchanges it for a token intended for a downstream API.
- **Backend or microservice calls:** A confidential service exchanges an incoming user token for a token whose resource and scopes are restricted to another internal service.
- **Cross-client exchange:** The subject token may have been issued to a different OIDC client, but only when that source client is explicitly selected in the requesting client's administrative Token Exchange policy. The client performing the exchange becomes the client of the newly issued token. The Token Exchange target, scope, group, and subject-client policies of the authenticated requesting client are authoritative.
- **Privilege reduction / downscoping:** A client can request a subset of the subject token's scopes for a specific configured resource.

Token Exchange must be enabled for the **requesting client**. The requesting client must be a confidential client and must authenticate at the token endpoint using exactly one supported client-authentication method (`client_secret_basic` or `client_secret_post`). Enabling Token Exchange requires an administrator to select at least one **allowed subject client** and configure at least one **allowed Token Exchange scope**. Both allow-lists are checked for every exchange, including same-client exchange. The scope policy is fail-closed: a TEX-enabled client with no configured `tex_allowed_scopes` cannot issue an exchanged token. Administrators must also configure resource targets for that client. Every exchange requires exactly one effective resource target (explicitly requested or inherited from the subject token), and that value is issued only if it exactly matches one of the configured Token Exchange target URIs.

### Supported request profile

The request uses the token endpoint with `POST` and **must** use `Content-Type: application/x-www-form-urlencoded` as defined by RFC 8693. Other content types are rejected with `invalid_request`. The original form body is the authoritative source for Token Exchange parameters so repeated fields cannot be hidden by PHP/Nextcloud parameter collapsing. The following profile is supported:

| Parameter | Support |
| --- | --- |
| `grant_type` | Required. Must be `urn:ietf:params:oauth:grant-type:token-exchange`. |
| `subject_token` | Required. Must be a valid, non-expired access token issued by this OIDC provider. |
| `subject_token_type` | Required. Only `urn:ietf:params:oauth:token-type:access_token` is supported. |
| `resource` | Optional in the request only when the subject token already contains a resource. Zero or one absolute URI is supported. Query components are allowed; fragments are not. Exactly one effective resource is required and must be configured as a Token Exchange target for the requesting client. |
| `scope` | Optional. May only reduce privileges. Every requested scope must already be present in the subject token and in the mandatory Token Exchange scope allow-list. If omitted (or sent with an empty value), the issued scope is the intersection of the subject-token scopes and `tex_allowed_scopes`. If that intersection is empty, the exchange is rejected with `invalid_scope`. |
| `requested_token_type` | Optional. If supplied, only `urn:ietf:params:oauth:token-type:access_token` is supported. |
| `audience` | Not supported. Any `audience` parameter is rejected with `invalid_target`. |
| `actor_token` / `actor_token_type` | Not supported. Delegation/actor semantics are rejected. |

RFC 8693 permits multiple `resource` and `audience` parameters. This implementation deliberately does not issue tokens for multiple target services. More than one `resource` parameter, including duplicate values, is rejected with `invalid_target`; any `audience` parameter is rejected as unsupported. All other Token Exchange parameters are singletons: required fields (`grant_type`, `subject_token`, and `subject_token_type`) must occur exactly once, while optional singleton fields such as `scope`, `requested_token_type`, actor fields, and body client credentials may occur at most once. Invalid repetitions are rejected with `invalid_request`. A mixed/repeated `grant_type` cannot be used to bypass this validation. Per OAuth 2.0 token-endpoint semantics (RFC 6749 section 3.2), form parameters whose decoded value is exactly empty are treated as omitted **before** presence, cardinality, unsupported-parameter, and client-authentication-method checks. Non-empty values, including whitespace-only values, remain present and are validated normally.

If `resource` is omitted, the resource of the subject token is inherited when present. The inherited value is **revalidated against the Token Exchange target allow-list of the requesting client** before it is copied to the new token. An unapproved inherited resource is rejected with `invalid_target`. If neither the request nor the subject token contains a resource, the exchange is rejected with `invalid_target`; this constrained profile does not fall back to the requesting client as an implicit Token Exchange audience.

Example request:

```http
POST /index.php/apps/oidc/token HTTP/1.1
Content-Type: application/x-www-form-urlencoded
Authorization: Basic <client-credentials>

grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Atoken-exchange
&subject_token=<access-token>
&subject_token_type=urn%3Aietf%3Aparams%3Aoauth%3Atoken-type%3Aaccess_token
&resource=https%3A%2F%2Fbackend.example.com%2Fapi
&scope=api.read
```

A successful response contains a bearer access token and identifies the issued token type:

```json
{
  "access_token": "<exchanged-access-token>",
  "issued_token_type": "urn:ietf:params:oauth:token-type:access_token",
  "token_type": "Bearer",
  "expires_in": 300,
  "scope": "api.read"
}
```

### Token properties and security policy

- The exchanged token represents the same user as the subject token.
- The exchanged token is associated with the authenticated **requesting client**, not necessarily the client to which the subject token was originally issued.
- The client to which the subject token was issued must be present in the requesting client's administrative **allowed subject clients** list. There is no implicit same-client or cross-client permission.
- This constrained profile uses RFC 8693 **impersonation semantics**, not delegation semantics: the exchanged token keeps the end user as `sub` and does not contain an `actor_token`/`act` chain. The administrative allow-list authorizes the requesting client to perform this impersonation-style exchange and intentionally overrides the normal per-user consent requirement for that requesting client. Token Exchange does not create or require a separate user-consent record for that client, so administrators must grant these trust relationships only to clients that are explicitly trusted for this purpose.
- The effective scope can never exceed the subject token scope. The Token Exchange scope allow-list is mandatory and provides a second, fail-closed upper bound. No configured TEX scopes means no Token Exchange token can be issued.
- The exchanged token never outlives the subject token. Its lifetime is the smaller of the configured access-token lifetime and the remaining lifetime of the subject token.
- Depending on the requesting client's access-token configuration, the exchanged token can be a JWT or an opaque access token.
- For exchanged JWT access tokens, `auth_time` is omitted. The exchange time is not an authentication time, and the implementation does not invent a new authentication event during Token Exchange.
- Every exchange has exactly one effective resource. It is used as the token audience/resource and is also returned as the audience by token introspection. Only allow-listed resource URIs can be set.
- The UserInfo resource check is applied specifically to **Token Exchange access tokens**, identified by their persisted parent-token lineage. A TEX token targeted at another backend resource is rejected by UserInfo with HTTP 401 / `invalid_token`. Access tokens issued by the normal authorization/refresh flows retain the historical UserInfo behavior, including existing deployments that store a `resource_url` on such tokens. To intentionally issue a TEX token for UserInfo, configure the exact discovered `userinfo_endpoint` as a Token Exchange target and request that URI as `resource`.
- The requesting client's configured user-group restrictions are applied before a token is issued.
- Public clients and clients for which Token Exchange is disabled receive `unauthorized_client`. Invalid `client_secret_basic` credentials receive HTTP 401 with a `WWW-Authenticate: Basic` challenge.
- Exchanged tokens persist the database ID of their immediate subject token as `parent_token_id`. The database enforces this lineage with a self-referencing foreign key and `ON DELETE CASCADE`. The migration removes any already-orphaned Token Exchange lineage before enabling the constraint. Token issuance is additionally serialized with subject-token revocation: before creating the child token, the token endpoint starts a short database transaction, acquires a write lock on the persisted subject-token row, re-reads it, and verifies that the access-token value plus the client, user, scope, resource, and expiry state used for authorization are still current. The lock is held until the child has been inserted, its final opaque/JWT value has been persisted, and the transaction commits. If revocation wins the race, the subject row can no longer be locked/re-read and the exchange fails with `invalid_request`; if the exchange wins, the complete child token is committed before revocation can proceed, after which `ON DELETE CASCADE` removes it. The foreign key remains a second fail-closed guard against orphan creation. The access-token mapper additionally performs recursive descendant deletion for normal application-level revocation, including multi-hop Token Exchange chains. This makes DB-backed checks such as introspection and UserInfo fail immediately after revocation. Refreshing/renewing an existing subject-token row does not by itself revoke descendants. Self-contained JWT access tokens that are validated **only offline** by a resource server can still remain cryptographically valid until their `exp` after a later, correctly ordered revocation; immediate revocation of such JWTs requires an online revocation/introspection mechanism at the resource server. The existing lifetime rule still ensures an exchanged token never outlives its subject token.

### Administration and OCC configuration

In the Admin UI, **Allowed Token Exchange Subject Clients** is a multi-select list of client IDs. At least one client and at least one **Allowed Token Exchange Scope** must be configured before Token Exchange can be enabled. Select the requesting client itself if same-client exchange is required; select additional client IDs only for explicitly trusted cross-client Token Exchange / impersonation paths. Scope configuration is deliberately fail-closed rather than interpreting an empty allow-list as "all subject-token scopes".

When creating a client with `occ oidc:create`, repeat `--tex_allowed_subject_client` for every accepted subject-token client, in the same way that `--tex_target_resource` can be repeated for target resources. For example:

```bash
occ oidc:create "Backend B" https://backend-b.example/callback \
  --client_id backend-b-client-id-0123456789012345 \
  --client_secret backend-b-secret-012345678901234 \
  --tex_enabled \
  --tex_allowed_subject_client frontend-a-client-id-012345678901 \
  --tex_allowed_subject_client backend-b-client-id-0123456789012345 \
  --tex_target_resource https://api.example/resource \
  --tex_allowed_scopes "openid profile"
```

When a newly created client should accept its own tokens, an explicit `--client_id` is required so the same identifier can also be supplied via `--tex_allowed_subject_client`. `occ oidc:create --tex_enabled` also requires a non-empty `--tex_allowed_scopes`. Existing clients that had Token Exchange enabled before the subject-client or mandatory scope allow-lists were introduced receive **no implicit trust or scope entries during migration** and therefore fail closed until an administrator configures both at least one allowed subject client and at least one allowed Token Exchange scope.

### Current limitations

The current implementation is not intended to cover every RFC 8693 deployment model. In particular:

- Only access-token-to-access-token exchange is supported.
- Only access tokens issued by this OIDC provider can be used as `subject_token`; external issuers and federation are not supported.
- Public clients cannot perform Token Exchange.
- Exactly one effective allow-listed resource URI is required per exchange; it can be supplied explicitly or inherited from the subject token.
- Logical `audience` parameters and combinations of `resource` plus `audience` are not supported.
- `actor_token` delegation and JWT `act` claim chains are not supported.
- No ID token or refresh token is issued by Token Exchange.
- Multiple resource targets are rejected instead of producing a token with a multi-valued audience.

These restrictions are intentional authorization-server policy choices. RFC 8693 allows an authorization server to reject target combinations it is unwilling or unable to fulfill with `invalid_target`.

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
| getUserFDOW | Return the users setting of first day of week or use the locale setting (0 = sunday, 1 = monday, ...) |
| getUserTimezone | Return the users setting of timezone or or forced by system |

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

- Both attributes must only contain characters as defined in [RFC 6749 (OAuth 2.0) Appendix A](https://datatracker.ietf.org/doc/html/rfc6749#appendix-A) which defines both as *VSCHAR (any printable ASCII, %x20-7E)
- Minimum length is 32 characters (for security reasons you should use the maximum length)
- Maximum length is 64 characters
- The client id must be unique

## Application settings via occ

Several global OIDC app settings can be changed with the Nextcloud `occ config:app:set` command. Run the commands from the Nextcloud installation directory and add the required PHP or web-server user prefix for your installation, if needed.

| Setting | Description | Example |
|---|---|---|
| `expire_time` | Access token and ID token lifetime in seconds. | `occ config:app:set oidc expire_time --value "1800"` |
| `refresh_expire_time` | Refresh token lifetime in seconds. Use `never` for refresh tokens that do not expire by time. | `occ config:app:set oidc refresh_expire_time --value "604800"` |
| `client_expire_time` | Lifetime of dynamically registered clients in seconds. | `occ config:app:set oidc client_expire_time --value "86400"` |
| `default_token_type` | Default access token type for newly created clients. Supported values are `opaque` and `jwt`. | `occ config:app:set oidc default_token_type --value "jwt"` |
| `provide_refresh_token_always` | Legacy mode for refresh tokens. Set to `true` to issue refresh tokens even without the `offline_access` scope; set to `false` for OIDC-compliant behavior. | `occ config:app:set oidc provide_refresh_token_always --value "false"` |
| `always_include_scope_claims` | Legacy mode for ID token claims in authorization code flow. Set to `true` to include scope claims in the ID token without an explicit `claims.id_token` request; set to `false` for OIDC-compliant behavior. | `occ config:app:set oidc always_include_scope_claims --value "false"` |
| `dynamic_client_registration` | Enables or disables Dynamic Client Registration and the `registration_endpoint` discovery metadata. Supported values are `true` and `false`. | `occ config:app:set oidc dynamic_client_registration --value "true"` |
| `overwrite_email_verified` | Set to `true` to always return `email_verified: true`; set to `false` to use the verification state from the Nextcloud account. | `occ config:app:set oidc overwrite_email_verified --value "false"` |
| `allow_user_settings` | Enables or disables personal privacy settings for users. Supported values are `enabled` and `no`. | `occ config:app:set oidc allow_user_settings --value "enabled"` |
| `restrict_user_information` | Globally removes selected optional profile data from ID token and UserInfo responses. Supported values are `avatar`, `address`, `phone`, `website`, or a space-separated combination. Use `no` to allow all supported optional profile data. | `occ config:app:set oidc restrict_user_information --value "avatar phone"` |
| `group_claim_type` | Controls whether the `groups` claim contains internal group IDs or display names. Supported values are `gid` and `displayname`. | `occ config:app:set oidc group_claim_type --value "displayname"` |
| `role_claim_type` | Controls whether the `roles` claim contains internal group IDs or display names. Supported values are `gid`, `displayname`, and `null`; `null` follows `group_claim_type`. | `occ config:app:set oidc role_claim_type --value "gid"` |
| `allow_subdomain_wildcards` | Enables or disables subdomain wildcards in redirect URIs, for example `https://*.example.com/callback`. Supported values are `true` and `false`. | `occ config:app:set oidc allow_subdomain_wildcards --value "true"` |
| `disable_auth_client_secret_basic` | Enables or disables support for the client_secret_basic authentication method. Supported values are `true` and `false`. | `occ config:app:set oidc disable_auth_client_secret_basic --value true` |

## JWT Access Tokens (RFC9068)

It is possible to activate the use of JWT based access tokens according to RFC9068. This can be done in the settings UI or while creating a client in the CLI. If not activated an opaque access token will be generated (as it was done previously).
