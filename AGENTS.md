<!-- SPDX-FileCopyrightText: 2026 Thorsten Jagel -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Nextcloud OIDC Identity Provider - Agent Guide

## Project Overview

**Nextcloud OIDC Identity Provider** enables Nextcloud to serve as an OpenID Connect (OIDC) provider, allowing users to authenticate at external services with their Nextcloud credentials. The app supports Authorization Code and Implicit flows, PKCE support, JWT access tokens, dynamic client registration, and user consent management.

- **Repository**: https://github.com/H2CK/oidc
- **License**: AGPL-3.0-or-later
- **Namespace**: `OCA\OIDCIdentityProvider`
- **Minimum Nextcloud**: 32 (appinfo/info.xml permits 32–35)
- **PHP Requirements**: 8.2+
- **Node Requirements**: 22.14+ or 24.x, npm 10+ (see package.json)

## Architecture

### Backend (PHP)

Located in `/lib`, organized by responsibility:

- **Controllers** - HTTP endpoints for OIDC flows, admin settings, user consent, CLI commands
- **Services** - Business logic for client management, token generation, JWT handling
- **Db** - Database models (mappers) for clients, tokens, consents, etc.
- **Exceptions** - Custom exceptions
- **Listener** - Event listeners for token generation and background jobs
- **Command** - CLI commands for client and claim management

Key PSR-4 namespace: `OCA\OIDCIdentityProvider\`

### Frontend (Vue 3 + Webpack)

Located in `/src`, exports four app entry points:

- **App.vue + main.js** - Admin settings UI (client management)
- **AppPersonal.vue + personal.js** - User consent/authorized apps view
- **Consent.vue + consent.js** - Authorization request UI (inline)
- **DeviceAuthorization.vue + device.js** - Device-code entry and approval

Components use Vue 3 and the Nextcloud libraries. Browser requests use `@nextcloud/axios` or `fetch`; obtain CSRF tokens with `getRequestToken()` from `@nextcloud/auth`.

### Templates

Located in `/templates`:
- `admin.php` and `personal.php` - Settings mount points
- `consent.php` and `device.php` - Authorization and device UI mount points
- `authorization-handoff.php` - Safe continuation after POST/login
- `logout_confirmation.php`, `logout_completion.php` - Browser logout pages
- `form-post.php` and `check_session_iframe.php` - Protocol response templates

## Development Workflow

### Initial Setup

```bash
make dev-setup       # Clean install: composer + npm
make install         # Production install (no-dev, optimized)
```

### Frontend Development

```bash
make build-js        # Single build (webpack dev)
make watch-js        # Live rebuild on file changes
make serve-js        # Start webpack dev server
make lint            # ESLint check
make lint-fix        # Auto-fix ESLint issues
make stylelint       # CSS/SCSS linting
make stylelint-fix   # Auto-fix styles
```

### Backend Development

```bash
make test            # Run all PHPUnit tests (unit + integration)
make test-unit       # Unit tests only
make test-integration # Integration tests only
npm run test:frontend # Dependency-free browser logic regression tests
```

### Building for Production

```bash
make build           # Build JS + assemble app (no tests)
make build-test      # Run tests before build
make appstore        # Sign app for Nextcloud App Store
```

## Code Conventions

### Frontend

- **Vue 3 Composition API** - Use `<script setup>` for new components
- **ESLint/Stylelint** - Run `make lint-fix` before committing
- **Naming** - PascalCase for components, camelCase for methods/props
- **i18n** - Use `t('oidc', 'Label')` for all user-facing strings
- **Nextcloud UI** - Prefer `@nextcloud/vue` components over custom HTML

### Backend

- **PSR-12** - PHP coding standard enforced by php-cs-fixer
- **Namespace**: `OCA\OIDCIdentityProvider\`
- **Autoloading**: PSR-4 via composer
- **Logging**: Use `Psr\Log\LoggerInterface`
- **Database**: Use Nextcloud Db abstraction with typed mappers

## Key Files & Features

### OIDC Flows

- **Authorization Code** - PKCE support, concrete redirect binding, independent 600-second code lifetime
- **Implicit** - Legacy support, requires explicit client configuration
- **RFC9068 JWT Access Tokens** - Optional, per-client
- **Offline Access** - Rotating refresh credentials retain their original scope; request scope narrows only the access token

### API Endpoints

- `/.well-known/openid-configuration` - Discovery endpoint
- `/index.php/apps/oidc/*` - OIDC endpoints (authorize, token, userinfo, etc.) / Admin UI APIs (not clearly separated)
- `/index.php/apps/oidc/api/*` - settings / consent APIs (non oauth flow API shall be migrated to this path. E.g., Admin UI APIs)

### Database Models

See `/lib/Db` for mappers:
- `Client` - OIDC client configurations
- `AccessToken`, `RefreshToken` - Token storage
- `AuthorizationCode` - Authorization code storage
- `UserConsent` - User permission records (90-day expiry)
- `DeviceCode` - Device authorization and approval state

Services include `AuthenticationTimeService` for active login evidence and `AuthorizationTransactionService` for single-use authorization continuations. Never derive auth_time from authorization or token issuance.

### CLI Commands

```bash
php occ oidc:create              # Create client
php occ oidc:list                # List clients
php occ oidc:remove              # Remove client
php occ oidc:create-claim        # Add custom claim
php occ oidc:list-claim          # List claims
php occ oidc:remove-claim        # Remove claim
php occ oidc:list-claim-functions # Show claim functions
```

## Common Development Tasks

### Adding a New Feature

1. **Backend**: Add controller method → register route in `routes.php` → add service logic
2. **Database**: Create mapper in `/lib/Db` if needed → add migration
3. **Frontend**: Create Vue component → add entry point in `/src` → call backend API
4. **Testing**: Add unit tests in `/tests` → run `make test`
5. **i18n**: Wrap strings with `t('oidc', 'Label')` → Transifex handles translations

### Debugging

- **Frontend**: Browser DevTools (Vue.js debugger available)
- **Backend**: Check `/var/log/nextcloud/nextcloud.log`
- **Tests**: Run `make test-unit` with subset: `./vendor/phpunit/phpunit/phpunit --filter=TestName`
- **Background Jobs**: `php occ background-job:execute --force-execute <id>`

### Releasing

Version updates required in four files (then commit):
1. `appinfo/info.xml` - `<version>` tag
2. `package.json` - `"version"` field
3. `package-lock.json` - root versions and dependency declarations matching package.json
4. `CHANGELOG.md` - New entry with changes

After version bump, run:
```bash
make build-test   # Verify build passes tests
```

## Important Constraints & Edge Cases

- **PKCE Mandatory for Public Clients** - Always required (RFC 7636)
- **Offline Access Scope** - Required for initial refresh issuance unless the configured legacy/trusted-event policy applies. Refresh rotation always issues a replacement. DCR clients must register the refresh grant.
- **OIDC vs OAuth** - Issue ID tokens and serve UserInfo only when openid was granted. OAuth-only requests remain supported.
- **Active Authentication** - Record time on UserLoggedInEvent; remember-me/app-token restoration is not active login evidence. Unknown old sessions must reauthenticate. max_age=0 requires an active login continuation.
- **Registration** - Store and enforce grant_types and response_types. Device-only registrations use response_types=[] and an explicit device grant.
- **Native Loopback** - RFC 8252 allows any HTTP loopback-IP port for native clients; preserve host, path and query exactly. Public web clients do not get this exception.
- **Redirect URI Validation** - Supports wildcards (port, path, subdomain with config)
- **Group Limitations** - Clients can restrict access to specific user groups
- **Email Verified** - Can source from Nextcloud account or force "always verified"

## Documentation & References

- [User Wiki](https://github.com/H2CK/oidc/wiki#user-documentation)
- [Developer Wiki](https://github.com/H2CK/oidc/wiki#developer-documentation)
- [OIDC Spec](https://openid.net/specs/openid-connect-core-1_0.html)
- [Nextcloud App Development](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/OCS/index.html)

## Translation Workflow

Translations managed via Transifex, not in the repository. New strings are auto-detected and sent to Transifex for translation by community.

---

**Last Updated**: October 2026 | **Version**: 2.5.1

ESLint 10 uses `eslint.config.mjs` and the named `recommended` flat configuration from `@nextcloud/eslint-config` 9. The old `.eslintrc.js` is ignored by ESLint 10. Do not remove PHP annotations required by Nextcloud 32/33 when adding attributes introduced in newer releases. Backend tests require a Nextcloud test installation; a JavaScript-only test run does not validate PHP, migrations or protocol conformance.
