<?php
/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method int getClientId()
 * @method void setClientId(int $clientId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int|null getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 */
class RegistrationToken extends Entity implements JsonSerializable {
	/** @var int */
	public $id;

	/** @var int */
	protected $clientId;

	/** @var string */
	protected $token;

	/** @var int */
	protected $createdAt;

	/** @var int|null */
	protected $expiresAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('token', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('expiresAt', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'client_id' => $this->clientId,
			'token' => $this->token,
			'created_at' => $this->createdAt,
			'expires_at' => $this->expiresAt,
		];
	}
}
