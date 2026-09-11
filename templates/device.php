<?php
/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
use OCP\Util;

Util::addScript('oidc', 'oidc-device');
?>

<div id="oidc-device"
    data-mode="<?php p($_['mode']); ?>"
    data-user-code="<?php p($_['userCode']); ?>"
    data-client-name="<?php p($_['clientName']); ?>"
    data-scope="<?php p($_['scope']); ?>"
    data-message="<?php p($_['message']); ?>">
</div>
