# Changelog

All notable changes to this project will be documented in this file.

## [1.12.1] - 2025-11-03

### Changed

- Fixed UI and translation issues
- Fixed DB migration steps
- Updated dependencies
- Updated translations

## [1.12.0] - 2025-11-03

### Added

- **OIDC Compliance: offline_access Scope Support (OpenID Connect Core 1.0 Section 11)**
  - Refresh tokens now require the `offline_access` scope to be requested
  - Discovery endpoint now advertises `offline_access` in `scopes_supported`
  - Enhanced consent screen displays offline_access permission with user-friendly description
  - Admin setting: "Refresh Token Behavior" toggle for legacy compatibility
    - Default (OIDC Compliant): Requires `offline_access` scope for refresh tokens
    - Legacy mode: Always provides refresh tokens (for non-compliant clients)
  - Automatic migration: Adds `offline_access` to all existing clients' allowed scopes
  - Comprehensive logging of refresh token issuance decisions
- Redirect uri wildcard support
  - End of path wildcard support (`.../*`)
  - Port wildcard for localhost (e.g. `http://localhost:*`)
  - Subdomain wildcard support (e.g. `https://*.example.com/callback`) - Must be activated via `occ config:app:set oidc allow_subdomain_wildcards --value "true"` Deactivation is possible with value `false`.

### Changed

- **Breaking Change for Non-Compliant Clients**: Clients must now explicitly request the `offline_access` scope to receive refresh tokens. This brings the OIDC provider into full compliance with OpenID Connect Core 1.0 specification. OIDC-compliant clients are unaffected. For clients that cannot be updated, enable "Legacy mode" in admin settings.

### Migration Guide

- **For OIDC-Compliant Clients**: No action required if you're already requesting `offline_access` scope
- **For Custom Clients**: Add `offline_access` to your authorization request scope parameter:
  - Before: `scope=openid profile email`
  - After: `scope=openid profile email offline_access`
- **For Non-Compliant Clients**: If updating the client is not possible, enable "Legacy mode" in Settings > OIDC > Refresh Token Behavior

## [1.11.0] - 2025-11-01

### Added

- Token Introspection (RFC 7662) (thanks to @cbcoutinho)
  - IntrospectionController: Complete introspection endpoint implementation
  - Client authentication: Supports both client_secret_basic and client_secret_post
  - Token validation: Checks token existence, expiration, and user validity
  - Metadata response: Returns active, scope, client_id, exp, iat, sub, aud
  - Secure defaults: Returns {active: false} for invalid/expired tokens without leaking info
- Client Configuration Management (RFC 7592) (thanks to @cbcoutinho)
  - RegistrationTokenService: Manages registration access tokens for client configuration endpoints
  - Registration access tokens: Cryptographically secure tokens (64 chars, ~380 bits entropy) for client management
  - Token lifecycle: Support for token generation, validation, rotation with grace period
  - Security: Tokens stored hashed, time-based expiration support
  - Future-ready: Enables client self-service for reading/updating their own configuration
- Dynamic Client Registration Enhancements (thanks to @cbcoutinho)
  - JWT token type: New token_type parameter accepting opaque or jwt
  - Enhanced scope validation: Allows OAuth-standard characters: :, ., /
  - Input validation: Proper normalization and error responses
  - Namespaced scopes: Support for patterns like read:messages, api:admin, org.example.scope
  - Registration tokens: Clients receive registration_access_token and registration_client_uri on creation
- User Consent Management (thanks to @cbcoutinho)
  - AuthorizedApps component: New settings panel showing all authorized applications
  - List endpoint (GET /api/consents): Retrieve all active consent grants for current user
  - Revoke endpoint (DELETE /api/consents/{clientId}): Self-service consent revocation
  - Displays client name, ID, granted scopes, and authorization date
  - Integrated into personal settings page

### Changed

- Updated dependencies
- Updated translations

## [1.10.0] - 2025-10-20

### Added

- Added PKCE support (thanks to @cbcoutinho)
- Updated dependencies
- Updated translations

## [1.9.0] - 2025-09-24

### Added

- Use clientId and clientSecret provided from external party during creation of a new client [#581](https://github.com/H2CK/oidc/issues/581)

### Changed

- Updated dependencies
- Updated translations

## [1.8.5] - 2025-09-19

### Changed

- Updated dependencies
- Updated translations

## [1.8.4] - 2025-09-02

### Changed

- Updated dependencies
- Updated translations

## [1.8.3] - 2025-07-24

### Changed

- Updated dependencies
- Updated translations

## [1.8.2] - 2025-07-21

### Changed

- Fixed icon in app list
- Fixed personal settings including issue [#577](https://github.com/H2CK/oidc/issues/577)
- Support for Nextcloud 32
- Updated dependencies
- Updated translations

## [1.8.1] - 2025-07-03

### Changed

- Updated translations

## [1.8.0] - 2025-07-01

### Changed

- Rework of admin settings - Settings are now in own section
- Added possibility to limit scopes per client
- Added possibility to define regex for selection of mail used within id token and userinfo endpoint
- Restrict information passed in ID token and provided at userinfo endpoint
- Allow users to restrict the provided information to external services on their own
- Updated dependencies
- Updated translations

## [1.7.2] - 2025-06-10

### Changed

- Fixed CORS handling
- Updated dependencies
- Updated translations

## [1.7.1] - 2025-05-21

### Changed

- Removed metadat in discovery endpoint for userinfo_signing_alg_values_supported
- Replace avatar data url with url provided by Nextcloud /avatar/userid/size
- Updated dependencies
- Updated translations

## [1.7.0] - 2025-05-06

### Changed

- Added possibility to use display name of groups in groups/roles claim (instead of group ID)
- Allow use of non-http/https redirect urls when creating a client
- Removed support for Nextcloud 28 and 29
- Switch to Vue 3
- Refactoring of code
- Added unnit tests
- Refactoring of documentation
- Updated dependencies
- Updated translations

## [1.6.1] - 2025-04-16

### Changed

- Added quota claim to discovery endpoint
- Changed security checks in user backend
- Updated dependencies
- Updated translations

## [1.6.0] - 2025-04-11

### Changed

- Added possibility to use RFC9068 conforming Access Tokens (configuration per client)
- Extended generation of tokens via event (provide scopes & resource)
- Updated dependencies
- Updated translations

## [1.5.0] - 2025-03-09

### Changed

- Add setting to choose the refresh token expiration time (thanks to @julien-nc)
- Updated dependencies
- Updated translations

## [1.4.0] - 2025-02-16

### Changed

- Enhancement to support event based generation and validation of id tokens (thanks to @julien-nc)
- Updated dependencies
- Updated translations

## [1.3.0] - 2025-01-26

### Changed

- Handle access token generation and validation requests via events (thanks to @julien-nc)
- Return user group array from the /userinfo endpoint and guard groups and roles claim behind authorized scope (thanks to @jannisko)
- Added support for Nextcloud 31
- Updated dependencies
- Updated translations

## [1.2.0] - 2024-12-25

### Changed

- Added support to provide claims family_name, given_name and middle_name JWT and userinfo endpoint (thanks to @ThoFrank)
- Added trimming of whitespaces to redirect and logout URIs on creation (thanks to @jannisko)
- Updated dependencies
- Updated translations

## [1.1.0] - 2024-12-11

### Changed

- Added support to provide quota in JWT
- Updated dependencies
- Updated translations

## [1.0.0] - 2024-10-22

### Changed

- Added CLI commands to manage clients (thanks to @opsocket)
- Updated dependencies
- Updated translations

## [0.9.4] - 2024-09-11

### Changed

- Fixed bug for lost session data in case of client authentication uses BasicAuth
- Updated dependencies
- Updated translations

## [0.9.3] - 2024-08-29

### Changed

- Added support for Nextcloud 30
- Removed support for Nextcloud 27
- Updated dependencies
- Updated translations
- Code cleanup and replacement of deprecated Nextcloud functions

## [0.9.2] - 2024-07-25

### Changed

- Improved CORS handlinng for simple requests
- Updated dependencies
- Updated translations

## [0.9.1] - 2024-06-23

### Changed

- Updated dependencies
- Updated translations

## [0.9.0] - 2024-05-10

### Changed

- Removed support for Nextcloud < 27
- Added BruteForce and RateLimiting functionality
- Added dynamic client registration functionalilty
- Limited Basic Authentication to token endpoint only

## [0.8.1] - 2024-04-16

### Changed

- Added support for Nextcloud 29
- Updated dependencies
- Updated translations  

## [0.8.0] - 2024-03-28

### Changed

- Support Basic Authentication for fetching the token using a workaround with a pseudo user backend. (But still this causes an exception of Nextcloud server core in the logs)  

## [0.7.4] - 2024-03-19

### Changed

- Dependency updates
- Allow overwriting claim verified_email

## [0.7.3] - 2023-12-23

### Changed

- Dependency updates
- Fix for claim verified_email

## [0.7.2] - 2023-12-14

### Changed

- Nextcloud 28 support
- Nextcloud 23 & 24 support removed
- Translations update
- Dependency updates
- Execute pipeline test on latest version
- Added tests

## [0.7.1] - 2023-10-29

### Changed

- Translations update
- Dependency updates

## [0.7.0] - 2023-08-06

### Changed

- Added claims phone_number and address
- Added possibility to add claim picture with data url to id token or user info dependent in app settings.
- Dependency updates

## [0.6.2] - 2023-07-28

### Changed

- Fixed expire time problem (now returns integer)
- Dependency updates

## [0.6.1] - 2023-07-11

### Changed

- Added Nextcloud 27 support
- Dependency updates

## [0.6.0] - 2023-05-23

### Changed

- Added WebFinger support

## [0.5.1] - 2023-05-13

### Changed

- Fixed user info endpoint for GET.

## [0.5.0] - 2023-05-12

### Changed

- Support for POST requests to user info endpoint.
- Removed Nextcloud session for token endpoint

## [0.4.9] - 2023-04-11

### Changed

- Fixed MySQL problem with index.

## [0.4.8] - 2023-04-03

### Changed

- Added support for post_logout_redirect_uri attribut during logout.

## [0.4.7] - 2023-03-26

### Changed

- Fixed logout functionality when id_token_hint is received.

## [0.4.6] - 2023-03-23

### Changed

- Fixed NC26 Support.
- Fixed packaging to include vendor.

## [0.4.5] - 2023-03-23

### Changed

- Fixed packaging to include vendor.

## [0.4.4] - 2023-03-22

### Changed

- Fixed logout support when providing an id_token_hint.
- Added support for Nextcloud 26

## [0.4.3] - 2023-03-15

### Changed

- Added partial support for RP-initiated logout.

## [0.4.2] - 2023-02-08

### Changed

- Fixed type in settings controller.

## [0.4.1] - 2023-02-08

### Changed

- Fixed bug to display settings menu for flows.

## [0.4.0] - 2023-02-04

### Changed

- Added support for implicit flow.

## [0.3.1] - 2023-01-31

### Changed

- Added translations.

## [0.3.0] - 2023-01-28

### Changed

- Added ability to limit clients to specific user groups.

## [0.2.10] - 2023-01-20

### Changed

- Fixed bug for jti claim which must be a string and not number.

## [0.2.9] - 2023-01-13

### Changed

- Updated package dependencies.

## [0.2.8] - 2022-12-13

### Changed

- Updated package dependencies.

## [0.2.7] - 2022-12-04

### Changed

- Updated package dependencies. Requires now Node 16.

## [0.2.6] - 2022-10-21

### Changed

- fixed urls at discovery endpoint for nextcloud installations in subdirectory.

## [0.2.5] - 2022-10-15

### Changed

- Updated translations.

## [0.2.4] - 2022-10-11

### Changed

- Fixed problem with php interpreter which prohibits to use settings panel.

## [0.2.3] - 2022-10-11

### Changed

- Fixed problem in migration schema

## [0.2.2] - 2022-10-11

### Changed

- Added possibility edit multiple redirect urls in admin panel.

## [0.2.1] - 2022-10-09

### Changed

- Modification to use app when Nextcloud is installed in subdirectory

## [0.2.0] - 2022-10-07

### Changed

- Added possibility to store multiple redirect urls in backend

## [0.1.11] - 2022-09-27

### Changed

- Fixed redired after login to make use of configured webroot

## [0.1.10] - 2022-09-14

### Changed

- Fix for url-encoding if state is missing

## [0.1.9] - 2022-09-12

### Changed

- Dependency Updates
- Updated translations from Transifex
- Fix url-encoding for state variable
- Support Nextcloud 25

## [0.1.8] - 2022-09-07

### Changed

- Dependency Updates
- Switch translations to Transifex

## [0.1.7] - 2022-06-25

### Changed

- Fix compatability for NC 21 & 22

## [0.1.6] - 2022-06-23

### Changed

- Increased robustness for not OpenID Connect conforming clients
- Allow scope to be unset from client. Default scope: openid profile email roles
- Allow using redirect urls which contain parameters

## [0.1.5] - 2022-05-15

### Changed

- Fixed remaining integrity check problem

## [0.1.4] - 2022-05-12

### Changed

- Fixed integrity check problem

## [0.1.3] - 2022-05-11

### Changed

- Modified dependency to php module instead of command for openssl

## [0.1.2] - 2022-05-09

### Changed

- Bugfix for setting use correctly conforming to OpenID Connect Specification

## [0.1.1] - 2022-05-01

### Added

- Added Spanish, Finnish, Swedish, Dutch, French, Italian and Greek translation
Bugfix to run clean up job to delete expired tokens from db successfully

### Changed

- Bugfix to run clean up job to delete expired tokens from db successfully

## [0.1.0] - 2022-04-13

### Added

- Added support for public clients
- Added Portuguese translation
- Added Github Actions to build & test application on commit and build, sign and publish to App Store

### Changed

- Optimized selection of token expire time

## [0.0.2] - 2022-03-20

### Added

- n/a

### Changed

- Fixed setting up of database tables to support MySQL / MariaDB

## [0.0.1] - 2022-03-02

### Added

- Base OIDC functionality
  - Configuration of accepted client for whom JWT Tokens are provided
  - Creation of JWT Token with claims based on requested scope. (Currently supported scopes openid, profile, email, roles, groups)
  - Supported siging algorithms RS256 (default) and HS256
  - Group memberships are passed as roles or groups in JWT token (depends on scope).
  - Discovery endpoint provided

### Changed

- n/a
