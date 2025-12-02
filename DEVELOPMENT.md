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
- CHANGELOG.md

## Execute test

Execute `make test` to run phpunit tests.

### Manual testing of BackgroundJobs

Execute  `php -dxdebug.remote_host=localhost -f cron.php` or use `php occ background-job:execute --force-execute <id>`.

Use `php occ background-job:list` to get necessary id for execution.

To run the job again if you have errors, however, you may have to remove it from the oc_jobs table and disable/reenable the app.
