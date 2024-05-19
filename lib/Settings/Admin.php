<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2022-2024 Thorsten Jagel <dev@jagel.net>
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
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IInitialStateService;
use OCP\Settings\ISettings;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class Admin implements ISettings {

	/** @var IInitialStateService */
	private $initialStateService;

	/** @var ClientMapper */
	private $clientMapper;

	/** @var RedirectUriMapper */
	private $redirectUriMapper;

	/** @var LogoutRedirectUriMapper */
	private $logoutRedirectUriMapper;

	/** @var GroupMapper */
	private $groupMapper;

	/** @var IGroupManager */
	private $groupManager;

	/** @var IAppConfig */
	private $appConfig;

	/** @var IL10N */
	private $l;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
					IInitialStateService $initialStateService,
					ClientMapper $clientMapper,
					RedirectUriMapper $redirectUriMapper,
					LogoutRedirectUriMapper $logoutRedirectUriMapper,
					GroupMapper $groupMapper,
					IGroupManager $groupManager,
					IL10N $l,
					IAppConfig $appConfig,
					LoggerInterface $logger
					)
	{
		$this->initialStateService = $initialStateService;
		$this->clientMapper = $clientMapper;
		$this->redirectUriMapper = $redirectUriMapper;
		$this->logoutRedirectUriMapper = $logoutRedirectUriMapper;
		$this->groupMapper = $groupMapper;
		$this->groupManager = $groupManager;
		$this->l = $l;
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
			$flowTypeLabel = $this->l->t('Code Authorization Flow');
			$responseTypeEntries = explode(' ', strtolower(trim($client->getFlowType())), 3);
			if (in_array('id_token', $responseTypeEntries)) {
				$flowTypeLabel = $this->l->t('Code & Implicit Authorization Flow');
			}

			$result[] = [
				'id' => $client->getId(),
				'name' => $client->getName(),
				'redirectUris' => $resultRedirectUris,
				'clientId' => $client->getClientIdentifier(),
				'clientSecret' => $client->getSecret(),
				'signingAlg' => $client->getSigningAlg(),
				'type' => $client->getType(),
				'flowType' => $client->getFlowType(),
				'flowTypeLabel' => $flowTypeLabel,
				'groups' => $resultGroups,
			];
		}

		$availableGroups = [];
		$allGroups = $this->groupManager->search('');
		foreach ($allGroups as $i => $group) {
			array_push($availableGroups, $group->getGID());
		}

		$logoutRedirectUrisResult = [];
		$logoutRedirectUris = $this->logoutRedirectUriMapper->getAll();
		foreach ($logoutRedirectUris as $logoutRedirectUri) {
			$logoutRedirectUrisResult[] = [
				'id' => $logoutRedirectUri->getId(),
				'redirectUri' => $logoutRedirectUri->getRedirectUri(),
			];
		}

		$this->logger->debug("Logout Redirect URIs provided: " . $this->arystr($logoutRedirectUrisResult, true, '|', ','));

		$this->initialStateService->provideInitialState('oidc', 'clients', $result);
		$this->initialStateService->provideInitialState('oidc', 'expireTime', $this->appConfig->getAppValue('expire_time'));
		$this->initialStateService->provideInitialState('oidc', 'publicKey', $this->appConfig->getAppValue('public_key'));
		$this->initialStateService->provideInitialState('oidc', 'groups', $availableGroups);
		$this->initialStateService->provideInitialState('oidc', 'logoutRedirectUris', $logoutRedirectUrisResult);
		$this->initialStateService->provideInitialState('oidc',
				'integrateAvatar', $this->appConfig->getAppValue('integrate_avatar'));
		$this->initialStateService->provideInitialState('oidc',
				'overwriteEmailVerified', $this->appConfig->getAppValue('overwrite_email_verified'));
		$this->initialStateService->provideInitialState('oidc',
				'dynamicClientRegistration', $this->appConfig->getAppValue('dynamic_client_registration'));

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

	private function arystr($ary='',$key=false,$rowsep=PHP_EOL,$cellsep=';') { // The two dimensional array, add keys name or not, Row separator, Cell Separator
		$str='';
		if (!is_array($ary)) {
			$str=strval($ary);
		} else if (count($ary)) {
			foreach ($ary as $k=>$t) {
				$str.=($key ? $k.$cellsep : '').(is_array($t) ? implode($cellsep,$t) : $t);
				end($ary);
				if ($k !== key($ary)) $str.=$rowsep;
			}
		}
		return $str;
	}
}
