<?php
/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
use OCP\Util;

Util::addScript('oidc', 'oidc-consent');

?>

<div id="oidc-consent"
     data-client-name="<?php p($_['clientName']); ?>"
     data-scopes="<?php p($_['requestedScopes']); ?>"
     data-client-id="<?php p($_['clientId']); ?>">
</div>
