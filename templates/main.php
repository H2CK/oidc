<?php
/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
 use OCP\Util;

 Util::addScript('oidc', 'oidc-redirect');

?>

<div id="oidc-redirect"
	<?php foreach ([
		'client_id', 'state', 'response_type', 'redirect_uri',
		'scope', 'nonce', 'resource', 'code_challenge', 'code_challenge_method',
	] as $key): ?>
		<?php if (!empty($_[$key] ?? null)): ?>
			data-<?php p(str_replace('_', '-', $key)); ?>="<?php p($_[$key]); ?>"
		<?php endif; ?>
	<?php endforeach; ?>
></div>
