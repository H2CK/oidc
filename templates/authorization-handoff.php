<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array<string, string> $_
 */

$continueUrl = htmlspecialchars($_['continueUrl'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$continueLabel = htmlspecialchars($_['continueLabel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<meta http-equiv="refresh" content="0;url=<?= $continueUrl ?>">
<p><a href="<?= $continueUrl ?>"><?= $continueLabel ?></a></p>
