<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Administrative allow-list entry for RFC 8693 subject-token clients.
 *
 * @method int getId()
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method int getSubjectClientId()
 * @method void setSubjectClientId(int $subjectClientId)
 */
class TexSubjectClient extends Entity {
    /** @var int */
    public $id;
    /** @var int Requesting client that performs Token Exchange. */
    protected $clientId;
    /** @var int Client to which an accepted subject token was issued. */
    protected $subjectClientId;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('clientId', 'int');
        $this->addType('subjectClientId', 'int');
    }
}
