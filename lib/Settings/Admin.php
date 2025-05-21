<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Settings;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\RedirectUri;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUriMapper;
use OCA\OIDCIdentityProvider\Db\LogoutRedirectUri;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\Group;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class Admin implements ISettings {

    /** @var IInitialState */
    private $initialState;

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
                    IInitialState $initialState,
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
        $this->initialState = $initialState;
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
                'tokenType' => $client->getTokenType()==='jwt' ? 'jwt' : 'opaque',
            ];
        }

        $availableGroups = [];
        $allGroups = $this->groupManager->search('');
        foreach ($allGroups as $group) {
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

        $this->initialState->provideInitialState('clients', $result);
        $this->initialState->provideInitialState('expireTime', $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME));
        $this->initialState->provideInitialState(
            'refreshExpireTime', $this->appConfig->getAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, Application::DEFAULT_REFRESH_EXPIRE_TIME)
        );
        $this->initialState->provideInitialState('publicKey', $this->appConfig->getAppValueString('public_key'));
        $this->initialState->provideInitialState('groups', $availableGroups);
        $this->initialState->provideInitialState('logoutRedirectUris', $logoutRedirectUrisResult);
        $this->initialState->provideInitialState(
                'overwriteEmailVerified', $this->appConfig->getAppValueString(Application::APP_CONFIG_OVERWRITE_EMAIL_VERIFIED));
        $this->initialState->provideInitialState(
                'dynamicClientRegistration', $this->appConfig->getAppValueString(Application::APP_CONFIG_DYNAMIC_CLIENT_REGISTRATION));

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
        } elseif (count($ary)) {
            foreach ($ary as $k=>$t) {
                $str.=($key ? $k.$cellsep : '').(is_array($t) ? implode($cellsep,$t) : $t);
                end($ary);
                if ($k !== key($ary)) {
                    $str.=$rowsep;
                }
            }
        }
        return $str;
    }
}
