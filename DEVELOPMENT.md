# Nextcloud OIDC App

## Development

To install it change into your Nextcloud's apps directory:

    cd nextcloud/apps

Then clone this repository.

Following install the development dependencies:

    make dev-setup

## Frontend development

The app requires to have Node and npm installed.

- 🏗 To build the necessary Javascript files whenever you make changes, run `make build-js`

To continuously run the build when editing source files you can make use of the `make watch-js` command.

## Translations

Translations are done using Transifex. If you like to contribute and do some translations please visit [Transifex](https://www.transifex.com/nextcloud/nextcloud/oidc/).

## Build app bundle

Execute `make build` to build for production bundle at build/artifacts. Perform `make appstore` to create tar.gz in build/artifacts.

### Releasing

To create a new release the following files must be modified and contain the new version.

- appinfo/info.xml
- package.json
- package-lock.json
- CHANGELOG.md

### Using the oidc-release skill

For automated release generation, you can use the `oidc-release` skill which automates version updates, changelog generation from merge requests, and build verification.

To use it, load the skill in your AI agent session:

```
skill name=oidc-release
```

The skill will guide you through the release process and handle the necessary updates to the version files and changelog.

## Execute test

Execute `make test` to run phpunit tests.

### Manual testing of BackgroundJobs

Execute  `php -dxdebug.remote_host=localhost -f cron.php` or use `php occ background-job:execute --force-execute <id>`.

Use `php occ background-job:list` to get necessary id for execution.

To run the job again if you have errors, however, you may have to remove it from the oc_jobs table and disable/reenable the app.

## Roadmap for OIDC compliance

The CI pipeline currently executes the OpenID Foundation conformance suite with the Basic OP and Config OP certification profiles:

```bash
oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]
oidcc-config-certification-test-plan[server_metadata=discovery][client_registration=static_client]
```

This verifies the basic authorization code flow with static clients and validates the OpenID Provider discovery metadata. It does not cover all OIDC response types advertised by the app discovery metadata.

Further conformance profiles to evaluate and implement in the pipeline:

- `oidcc-hybrid-certification-test-plan`: validates Hybrid OP behavior, including `response_type=code id_token`, `code token`, and `code id_token token`. This is relevant because the app currently supports `code id_token` as a configurable client flow.
- `oidcc-implicit-certification-test-plan`: validates Implicit OP behavior, including `response_type=id_token` and `id_token token`. Add this only if legacy implicit flow support is intentionally kept.
- `oidcc-formpost-basic-certification-test-plan`, `oidcc-formpost-implicit-certification-test-plan`, and `oidcc-formpost-hybrid-certification-test-plan`: validate `response_mode=form_post`. These are only relevant if the app implements and advertises form post response mode.
- Logout certification profiles such as RP-initiated logout, session management, front-channel logout, and back-channel logout should be considered if those features are intended to be fully OIDC-certified.
- `oidcc-dynamic-certification-test-plan`: validates Dynamic Client Registration. This is relevant when dynamic client registration is enabled and intended to be certified.

Before enabling implicit or hybrid profile tests as required CI checks, align the implementation and discovery metadata:

- Discovery currently advertises `implicit` grant support and `id_token` / `code id_token` response types.
- The authorization response currently returns front-channel parameters through query serialization. Implicit and hybrid OIDC responses normally require fragment handling unless another supported response mode is used.
- If implicit flow support is not a strategic goal, prefer removing or narrowing the advertised implicit capabilities instead of certifying them.
- If implicit or hybrid support is kept, add real browser-level conformance coverage rather than relying only on integration tests that create tokens directly.

Modern OAuth security guidance discourages new use of implicit/access-token-in-front-channel flows. Prefer authorization code flow with PKCE for new clients, and treat implicit support as legacy compatibility.
