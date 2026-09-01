<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @var array{
 *     action: string,
 *     cancelUrl: string,
 *     confirmationToken: string,
 *     title: string,
 *     message: string,
 *     logoutLabel: string,
 *     cancelLabel: string
 * } $_
 */
?>

<div id="oidc-logout-confirmation" class="guest-box" role="dialog" aria-labelledby="oidc-logout-confirmation-title">
    <h2 id="oidc-logout-confirmation-title"><?php p($_['title']); ?></h2>
    <p><?php p($_['message']); ?></p>

    <form method="post" action="<?php p($_['action']); ?>" autocomplete="off">
        <input type="hidden" name="confirm_logout" value="1">
        <input type="hidden" name="logout_confirmation_token" value="<?php p($_['confirmationToken']); ?>">

        <p class="margin-top">
            <button type="submit" class="primary"><?php p($_['logoutLabel']); ?></button>
        </p>
    </form>

    <p>
        <a class="button" href="<?php p($_['cancelUrl']); ?>"><?php p($_['cancelLabel']); ?></a>
    </p>
</div>
