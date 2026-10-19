# Security and hardening follow-up

## Token generation event metadata

- [x] Extend `TokenGenerationRequestEvent` with an optional expected audience and return the resolved client, effective scope, audience, and resource after successful issuance.
- [x] Keep current constructor calls temporarily compatible while emitting a deprecation notice when the expected-audience argument is omitted.
- [x] Reject issuance when a supplied expected audience does not match the audience resolved from the resource or client configuration.
- [x] Document the metadata contract and test audience mismatch, scope clamping, client selection, resource resolution, and legacy-call deprecation.
- [ ] Remove the legacy constructor form in a future release.

## Hash bearer credentials at rest

- Replace plaintext persistence of OIDC access tokens and dynamic-client-registration tokens with cryptographic hashes, retaining the ability to validate presented tokens securely.
- Design a migration for existing live credentials. Consider a bounded compatibility period or forced rotation where safe lookup cannot be preserved without retaining plaintext.
- Review mapper lookups, token rotation/revocation, registration management, cleanup jobs, and logging so raw credentials are not exposed or logged.
- Add migration and regression tests, including verification that database rows and logs do not contain raw token values.

## Canonical issuer URL

- Centralize issuer construction instead of independently deriving it from request protocol and host in discovery, token generation/validation, UserInfo, introspection, and logout paths.
- Use a canonical Nextcloud instance URL that respects supported reverse-proxy and overwrite-URL configuration; do not let an untrusted request Host value change the issuer.
- Add tests that all protocol endpoints use the same issuer behind proxy configurations and reject or safely handle unexpected Host values.
