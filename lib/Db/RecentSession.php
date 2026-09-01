<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Short-lived history entry used to correlate RP-Initiated Logout hints with
 * an OP/RP session that was genuinely logged out recently.
 *
 * @method int getId()
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getClientIdentifier()
 * @method void setClientIdentifier(string $clientIdentifier)
 * @method string getSid()
 * @method void setSid(string $sid)
 * @method int getLoggedOutAt()
 * @method void setLoggedOutAt(int $loggedOutAt)
 */
class RecentSession extends Entity {
    protected $userId;
    protected $clientIdentifier;
    protected $sid;
    protected $loggedOutAt;

    public function __construct() {
        $this->addType('id', 'int');
        $this->addType('userId', 'string');
        $this->addType('clientIdentifier', 'string');
        $this->addType('sid', 'string');
        $this->addType('loggedOutAt', 'int');
    }
}
