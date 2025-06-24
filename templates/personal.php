<?php
/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
//use OCP\Util;

/** @var $l \OCP\IL10N */
/** @var $_ array */

//Util::addScript('oidc', 'oidc-personal');

?>

<div id="oidc_personal">
    <h2><?php p($l->t('OpenID Connect Provider')); ?></h2>
    <?php if ($_['allow_user_settings']=='no'): ?>
        <p><?php p($l->t('All settings for the login at other services using the OpenID Connect Provider app are managed by your administrator.')); ?></p>
    <?php endif; ?>
</div>
