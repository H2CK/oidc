# Changelog
All notable changes to this project will be documented in this file.

##  [0.0.1] - 2022-02-20
### Added
- Base OIDC functionality
  - Configuration of accepted client for whom JWT Tokens are provided
  - Creation of JWT Token with claims based on requested scope. (Currently supported scopes openid, profile, email)
  - Supported siging algorithms RS256 (default) and HS256
  - Group memberships are passed as roles in JWT token.
  - Discovery endpoint provided

### Changed
- n/a
