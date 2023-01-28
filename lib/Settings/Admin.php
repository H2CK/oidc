<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2023 Thorsten Jagel <dev@jagel.net>
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
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\Settings\ISettings;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class Admin implements ISettings {

	/** @var IInitialStateService */
	private $initialStateService;

	/** @var ClientMapper */
	private $clientMapper;

	/** @var RedirectUriMapper */
	private $redirectUriMapper;

	/** @var GroupMapper */
	private $groupMapper;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IAppConfig */
	private $appConfig;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
					IInitialStateService $initialStateService,
					ClientMapper $clientMapper,
					RedirectUriMapper $redirectUriMapper,
					GroupMapper $groupMapper,
					IGroupManager $groupManager,
					IAppConfig $appConfig,
					LoggerInterface $logger
					)
	{
		$this->initialStateService = $initialStateService;
		$this->clientMapper = $clientMapper;
		$this->redirectUriMapper = $redirectUriMapper;
		$this->groupMapper = $groupMapper;
		$this->groupManager = $groupManager;
		$this->appConfig = $appConfig;
		$this->logger = $logger;
	}

	public function getForm(): TemplateResponse
	{
		$clients = $this->clientMapper->getClients();
		$result = [];

		foreach ($clients as $client) {
			$redirectUris = $this->redirectUriMapper->getByClientId($client->getId());
			$resultRedirectUris = [];
			foreach ($redirectUris as $redirectUri) {
				$resultRedirectUris[] = [
					'id' => $redirectUri->getId(),
					'client_id' => $redirectUri->getClientId(),
					'redirect_uri' => $redirectUri->getRedirectUri(),
				];
			}
			$groups = $this->groupMapper->getGroupsByClientId($client->getId());
			$resultGroups = [];
			foreach ($groups as $group) {
				array_push($resultGroups, $group->getGroupId());
			}
			$result[] = [
				'id' => $client->getId(),
				'name' => $client->getName(),
				'redirectUris' => $resultRedirectUris,
				'clientId' => $client->getClientIdentifier(),
				'clientSecret' => $client->getSecret(),
				'signingAlg' => $client->getSigningAlg(),
				'type' => $client->getType(),
				'groups' => $resultGroups,
			];
		}

		$availableGroups = [];
		$allGroups = $this->groupManager->search('');
		foreach ($allGroups as $i => $group) {
			array_push($availableGroups, $group->getGID());
		}

		$this->initialStateService->provideInitialState('oidc', 'clients', $result);
		$this->initialStateService->provideInitialState('oidc', 'expireTime', $this->appConfig->getAppValue('expire_time'));
		$this->initialStateService->provideInitialState('oidc', 'publicKey', $this->appConfig->getAppValue('public_key'));
		$this->initialStateService->provideInitialState('oidc', 'groups', $availableGroups);

		return new TemplateResponse(
						'oidc',
						'admin',
						[],
						''
						);
	}

	public function getSection(): string
	{
		return 'security';
	}

	public function getPriority(): int
	{
		return 150;
	}
}
