#!/usr/bin/env bash
set -euo pipefail

: "${GITHUB_WORKSPACE:?GITHUB_WORKSPACE is required}"
: "${RUNNER_TEMP:?RUNNER_TEMP is required}"

OIDC_TEST_USER="${OIDC_TEST_USER:-oidc-test-user}"
OIDC_TEST_PASSWORD="${OIDC_TEST_PASSWORD:-oidc-test-password}"
OIDC_TEST_EMAIL="${OIDC_TEST_EMAIL:-oidc-test@example.invalid}"
OAUTH_CALLBACK_URI="${OAUTH_CALLBACK_URI:-https://oauth-callback:9444/callback}"
OAUTH_CLIENT_ID="${OAUTH_CLIENT_ID:-oauth-conformance-client-000000000001}"
OAUTH_CLIENT_SECRET="${OAUTH_CLIENT_SECRET:-oauth-conformance-secret-0000000001}"
OAUTH_SECOND_CLIENT_ID="${OAUTH_SECOND_CLIENT_ID:-oauth-conformance-client-000000000002}"
OAUTH_SECOND_CLIENT_SECRET="${OAUTH_SECOND_CLIENT_SECRET:-oauth-conformance-secret-0000000002}"
OAUCH_CLIENT_ID="${OAUCH_CLIENT_ID:-oauch-conformance-client-00000000001}"
OAUCH_CLIENT_SECRET="${OAUCH_CLIENT_SECRET:-oauch-conformance-secret-00000000001}"
OAUCH_CALLBACK_URI="${OAUCH_CALLBACK_URI:-https://oauch.io/Callback}"
RESULT_DIR="${OAUTH_RESULTS_DIR:-${GITHUB_WORKSPACE}/oauth-results}"
NEXTCLOUD_DIR="${RUNNER_TEMP}/nextcloud"

mkdir -p "$RESULT_DIR"

MAX_VERSION="$(python3 - <<'PY'
import xml.etree.ElementTree as ET
root = ET.parse('appinfo/info.xml').getroot()
deps = root.find('dependencies')
nc = deps.find('nextcloud') if deps is not None else None
if nc is None or not nc.attrib.get('max-version'):
    raise SystemExit('appinfo/info.xml has no nextcloud max-version')
print(nc.attrib['max-version'].split('.')[0])
PY
)"

rm -rf "$NEXTCLOUD_DIR"
git clone --depth 1 --recurse-submodules --shallow-submodules \
  --branch "stable${MAX_VERSION}" https://github.com/nextcloud/server.git "$NEXTCLOUD_DIR"
(
  cd "$NEXTCLOUD_DIR"
  composer install --no-interaction --prefer-dist --no-progress
)

rm -rf "$NEXTCLOUD_DIR/apps/oidc"
mkdir -p "$NEXTCLOUD_DIR/apps/oidc"
rsync -a --delete \
  --exclude=.git \
  --exclude=node_modules \
  --exclude=oauth-results \
  --exclude=oauch-results \
  "$GITHUB_WORKSPACE/" "$NEXTCLOUD_DIR/apps/oidc/"

(
  cd "$NEXTCLOUD_DIR"
  php occ maintenance:install --database sqlite --admin-user admin --admin-pass admin
  php occ app:enable oidc

  php occ config:system:set trusted_domains 1 --value=nextcloud-proxy
  php occ config:system:set trusted_domains 2 --value=127.0.0.1
  php occ config:system:set trusted_domains 3 --value=localhost
  php occ config:system:set overwrite.cli.url --value="https://nextcloud-proxy:8443"
  php occ config:system:set overwritehost --value="nextcloud-proxy:8443"
  php occ config:system:set overwriteprotocol --value=https

  php occ config:app:set oidc allow_user_settings --value=no
  php occ config:app:set oidc provide_refresh_token_always --value=false
  php occ config:app:set oidc dynamic_client_registration --value=true

  export OC_PASS="$OIDC_TEST_PASSWORD"
  php occ user:add --password-from-env --display-name="OIDC Test User" "$OIDC_TEST_USER"
  php occ user:setting "$OIDC_TEST_USER" settings email "$OIDC_TEST_EMAIL"

  php occ oidc:create "OAuth conformance client" "$OAUTH_CALLBACK_URI" \
    --client_id "$OAUTH_CLIENT_ID" \
    --client_secret "$OAUTH_CLIENT_SECRET" \
    --type confidential \
    --flow code \
    --allowed_scopes "openid profile email roles groups offline_access" \
    --tex_enabled \
    --tex_allowed_scopes "openid profile email roles groups offline_access" \
    --tex_allowed_subject_client "$OAUTH_CLIENT_ID"

  php occ oidc:create "OAuth conformance second client" "$OAUTH_CALLBACK_URI" \
    --client_id "$OAUTH_SECOND_CLIENT_ID" \
    --client_secret "$OAUTH_SECOND_CLIENT_SECRET" \
    --type confidential \
    --flow code \
    --allowed_scopes "openid profile email roles groups offline_access"

  # OAuch always acts as its own client. Creating this client here keeps the
  # OAuch workflow independent from the deterministic pytest client.
  php occ oidc:create "OAuch conformance client" "$OAUCH_CALLBACK_URI" \
    --client_id "$OAUCH_CLIENT_ID" \
    --client_secret "$OAUCH_CLIENT_SECRET" \
    --type confidential \
    --flow code \
    --allowed_scopes "openid profile email offline_access"
)

# Keep the development web server alive for subsequent workflow steps.
(
  cd "$NEXTCLOUD_DIR"
  nohup php -S 0.0.0.0:8080 -t . >"$RESULT_DIR/nextcloud-server.log" 2>&1 &
  echo $! >"$RESULT_DIR/nextcloud-server.pid"
)

echo "NEXTCLOUD_DIR=$NEXTCLOUD_DIR" >> "${GITHUB_ENV:-/dev/null}"
