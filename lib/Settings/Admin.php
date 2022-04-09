<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022 Thorsten Jagel <dev@jagel.net>
 *
 * @author Thorsten Jagel <dev@jagel.net>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\OIDCIdentityProvider\Settings;

use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\Settings\ISettings;
use OCP\AppFramework\Services\IAppConfig;

class Admin implements ISettings {

	/** @var IInitialStateService */
	private $initialStateService;

	/** @var ClientMapper */
	private $clientMapper;

	/** @var IAppConfig */
	private $appConfig;

	public function __construct(IInitialStateService $initialStateService,
								ClientMapper $clientMapper,
								IAppConfig $appConfig) {
		$this->initialStateService = $initialStateService;
		$this->clientMapper = $clientMapper;
		$this->appConfig = $appConfig;
	}

	public function getForm(): TemplateResponse {
		$clients = $this->clientMapper->getClients();
		$result = [];

		foreach ($clients as $client) {
			$result[] = [
				'id' => $client->getId(),
				'name' => $client->getName(),
				'redirectUri' => $client->getRedirectUri(),
				'clientId' => $client->getClientIdentifier(),
				'clientSecret' => $client->getSecret(),
				'signingAlg' => $client->getSigningAlg(),
				'type' => $client->getType(),
			];
		}
		$this->initialStateService->provideInitialState('oidc', 'clients', $result);
		$this->initialStateService->provideInitialState('oidc', 'expireTime', $this->appConfig->getAppValue('expire_time'));
		$this->initialStateService->provideInitialState('oidc', 'publicKey', $this->appConfig->getAppValue('public_key'));

		return new TemplateResponse(
			'oidc',
			'admin',
			[],
			''
		);
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 150;
	}
}
