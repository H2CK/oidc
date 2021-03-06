# Changelog

All notable changes to this project will be documented in this file.

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
