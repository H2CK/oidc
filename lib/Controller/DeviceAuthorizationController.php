<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCode;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Exceptions\ClientNotFoundException;
use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class DeviceAuthorizationController extends Controller {
	private const DEVICE_CODE_LIFETIME = 600;
	private const INITIAL_POLL_INTERVAL = 5;
	private const USER_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
	private const DEVICE_CODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';

	public function __construct(
		string $appName,
		IRequest $request,
		private ClientMapper $clientMapper,
		private DeviceCodeMapper $deviceCodeMapper,
		private GroupMapper $groupMapper,
		private UserConsentMapper $userConsentMapper,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ISecureRandom $secureRandom,
		private ITimeFactory $time,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private LoggerInterface $logger,
		private FormUrlencodedParameterParser $formUrlencodedParameterParser,
	) {
		parent::__construct($appName, $request);
	}

	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: 'oidc_device_authorization')]
	#[NoCSRFRequired]
	#[PublicPage]
	public function authorize(
		?string $client_id = null,
		?string $scope = null,
		?string $client_secret = null,
	): JSONResponse {
		if (!$this->isFormUrlencodedRequest()) {
			return $this->oauthError('invalid_request', 'Device authorization requests must use application/x-www-form-urlencoded.');
		}

		$parameters = $this->formUrlencodedParameterParser->readSelectedParameters([
			'client_id',
			'client_secret',
			'scope',
		]);
		if ($parameters === null) {
			return $this->oauthError('invalid_request', 'Could not parse the form body.');
		}
		foreach ($parameters as $name => $values) {
			$nonEmpty = array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
			if (count($nonEmpty) > 1) {
				return $this->oauthError('invalid_request', 'Parameter ' . $name . ' must not occur more than once.');
			}
		}

		$basicAuthenticationAttempted = $this->hasBasicAuthorizationHeader();
		$basicCredentials = $this->getBasicClientCredentials();
		if ($basicAuthenticationAttempted && $basicCredentials === null) {
			return $this->invalidClient('Malformed client credentials.', true);
		}
		if ($basicCredentials !== null && ($client_id !== null || $client_secret !== null)) {
			return $this->oauthError('invalid_request', 'Use exactly one client authentication method.');
		}
		if ($basicCredentials !== null) {
			[$client_id, $client_secret] = $basicCredentials;
		}

		$clientOrResponse = $this->authenticateClient($client_id, $client_secret, $basicAuthenticationAttempted);
		if ($clientOrResponse instanceof JSONResponse) {
			return $clientOrResponse;
		}
		$client = $clientOrResponse;

		$scopeOrResponse = $this->normalizeScope($scope, $client);
		if ($scopeOrResponse instanceof JSONResponse) {
			return $scopeOrResponse;
		}

		$deviceCode = $this->secureRandom->generate(64, self::DEVICE_CODE_ALPHABET);
		$normalizedUserCode = $this->generateUniqueUserCode();
		$displayUserCode = substr($normalizedUserCode, 0, 4) . '-' . substr($normalizedUserCode, 4);
		$now = $this->time->getTime();

		$entity = new DeviceCode();
		$entity->setClientId($client->getId());
		$entity->setHashedDeviceCode(hash('sha512', $deviceCode));
		$entity->setHashedUserCode(hash('sha512', $normalizedUserCode));
		$entity->setScope($scopeOrResponse);
		$entity->setCreatedAt($now);
		$entity->setExpiresAt($now + self::DEVICE_CODE_LIFETIME);
		$entity->setIntervalSeconds(self::INITIAL_POLL_INTERVAL);
		$entity->setLastPolledAt(0);
		$entity->setStatus(DeviceCode::STATUS_PENDING);
		$entity->setUserId(null);
		$entity->setConsumedAt(0);
		$this->deviceCodeMapper->insert($entity);

		$verificationUri = $this->urlGenerator->linkToRouteAbsolute('oidc.DeviceAuthorization.verify', []);
		$response = new JSONResponse([
			'device_code' => $deviceCode,
			'user_code' => $displayUserCode,
			'verification_uri' => $verificationUri,
			'verification_uri_complete' => $verificationUri . '?user_code=' . rawurlencode($displayUserCode),
			'expires_in' => self::DEVICE_CODE_LIFETIME,
			'interval' => self::INITIAL_POLL_INTERVAL,
		]);
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Pragma', 'no-cache');
		return $response;
	}

	#[NoCSRFRequired]
	#[PublicPage]
	#[UseSession]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: 'oidc_device_verification')]
	public function verify(?string $user_code = null): Response {
		$normalizedUserCode = DeviceCodeMapper::normalizeUserCode((string)$user_code);
		if ($normalizedUserCode === '') {
			return $this->devicePage('enter', null, null, null);
		}

		$deviceCode = $this->deviceCodeMapper->findByUserCode($normalizedUserCode);
		if ($deviceCode === null || $this->time->getTime() >= $deviceCode->getExpiresAt()) {
			return $this->devicePage('error', $normalizedUserCode, null, $this->l->t('The device code is invalid or has expired.'));
		}
		if ($deviceCode->getStatus() === DeviceCode::STATUS_DENIED) {
			return $this->devicePage('error', $normalizedUserCode, null, $this->l->t('This device request was denied.'));
		}
		if ($deviceCode->getStatus() !== DeviceCode::STATUS_PENDING) {
			return $this->devicePage('complete', $normalizedUserCode, null, null);
		}

		if (!$this->userSession->isLoggedIn()) {
			$returnUrl = $this->urlGenerator->linkToRoute('oidc.DeviceAuthorization.verify', [
				'user_code' => $normalizedUserCode,
			]);
			return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm', [
				'redirect_url' => $returnUrl,
			]));
		}

		try {
			$client = $this->clientMapper->getByUid($deviceCode->getClientId());
		} catch (ClientNotFoundException $e) {
			return $this->devicePage('error', $normalizedUserCode, null, $this->l->t('The requesting application no longer exists.'));
		}

		return $this->devicePage('approve', $normalizedUserCode, $client, null, $deviceCode->getScope());
	}

	#[NoAdminRequired]
	#[UseSession]
	public function approve(string $user_code): JSONResponse {
		$deviceCodeOrResponse = $this->loadPendingDeviceCode($user_code);
		if ($deviceCodeOrResponse instanceof JSONResponse) {
			return $deviceCodeOrResponse;
		}
		$deviceCode = $deviceCodeOrResponse;
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'login_required'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$client = $this->clientMapper->getByUid($deviceCode->getClientId());
		} catch (ClientNotFoundException $e) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->isUserAllowedForClient($user, $client)) {
			return new JSONResponse(['error' => 'access_denied'], Http::STATUS_FORBIDDEN);
		}

		if (!$this->deviceCodeMapper->markApproved($deviceCode, $user->getUID())) {
			return new JSONResponse(['error' => 'invalid_request', 'error_description' => 'The request is no longer pending.'], Http::STATUS_CONFLICT);
		}
		$this->storeConsent($user->getUID(), $client, $deviceCode->getScope());
		$this->logger->info('User approved an OAuth device authorization request.', ['client_id' => $client->getClientIdentifier()]);
		return new JSONResponse(['success' => true]);
	}

	#[NoAdminRequired]
	#[UseSession]
	public function deny(string $user_code): JSONResponse {
		$deviceCodeOrResponse = $this->loadPendingDeviceCode($user_code);
		if ($deviceCodeOrResponse instanceof JSONResponse) {
			return $deviceCodeOrResponse;
		}
		if (!$this->deviceCodeMapper->markDenied($deviceCodeOrResponse)) {
			return new JSONResponse(['error' => 'invalid_request', 'error_description' => 'The request is no longer pending.'], Http::STATUS_CONFLICT);
		}
		return new JSONResponse(['success' => true]);
	}

	private function isFormUrlencodedRequest(): bool {
		$contentType = strtolower(trim(explode(';', $this->request->getHeader('Content-Type'), 2)[0]));
		return $contentType === 'application/x-www-form-urlencoded';
	}

	private function getBasicClientCredentials(): ?array {
		$authorization = $this->request->getHeader('Authorization');
		if (stripos($authorization, 'Basic ') !== 0) {
			return null;
		}
		$decoded = base64_decode(trim(substr($authorization, 6)), true);
		if ($decoded === false || !str_contains($decoded, ':')) {
			return null;
		}
		[$clientId, $clientSecret] = explode(':', $decoded, 2);
		return [urldecode($clientId), urldecode($clientSecret)];
	}

	private function hasBasicAuthorizationHeader(): bool {
		return stripos(trim($this->request->getHeader('Authorization')), 'Basic ') === 0;
	}

	private function authenticateClient(
		?string $clientId,
		?string $clientSecret,
		bool $basicAuthenticationAttempted,
	): Client|JSONResponse {
		if ($clientId === null || trim($clientId) === '') {
			return $this->invalidClient('Missing client_id.', $basicAuthenticationAttempted);
		}
		try {
			$client = $this->clientMapper->getByIdentifier($clientId);
		} catch (ClientNotFoundException $e) {
			return $this->invalidClient('Client not found.', $basicAuthenticationAttempted);
		}
		if ($client === null) {
			return $this->invalidClient('Client not found.', $basicAuthenticationAttempted);
		}
		if ($client->getType() !== 'public' && (!is_string($clientSecret) || !hash_equals($client->getSecret(), $clientSecret))) {
			return $this->invalidClient('Client authentication failed.', $basicAuthenticationAttempted);
		}
		return $client;
	}

	private function normalizeScope(?string $scope, Client $client): string|JSONResponse {
		$requested = preg_split('/\s+/', strtolower(trim($scope ?? Application::DEFAULT_SCOPE)), -1, PREG_SPLIT_NO_EMPTY);
		if (!in_array('openid', $requested, true)) {
			$requested[] = 'openid';
		}
		$requested = array_values(array_unique($requested));
		$allowed = preg_split('/\s+/', strtolower(trim($client->getAllowedScopes())), -1, PREG_SPLIT_NO_EMPTY);
		if ($allowed !== [] && array_diff($requested, $allowed) !== []) {
			return $this->oauthError('invalid_scope', 'One or more requested scopes are not allowed for this client.');
		}
		return substr(implode(' ', $requested), 0, 512);
	}

	private function generateUniqueUserCode(): string {
		for ($attempt = 0; $attempt < 10; ++$attempt) {
			$candidate = $this->secureRandom->generate(8, self::USER_CODE_ALPHABET);
			if ($this->deviceCodeMapper->findByUserCode($candidate) === null) {
				return $candidate;
			}
		}
		throw new \RuntimeException('Could not allocate a unique device user code.');
	}

	private function loadPendingDeviceCode(string $userCode): DeviceCode|JSONResponse {
		$deviceCode = $this->deviceCodeMapper->findByUserCode($userCode);
		if ($deviceCode === null || $this->time->getTime() >= $deviceCode->getExpiresAt()) {
			return new JSONResponse(['error' => 'invalid_request', 'error_description' => 'The device code is invalid or expired.'], Http::STATUS_BAD_REQUEST);
		}
		if ($deviceCode->getStatus() !== DeviceCode::STATUS_PENDING) {
			return new JSONResponse(['error' => 'invalid_request', 'error_description' => 'The request is no longer pending.'], Http::STATUS_CONFLICT);
		}
		return $deviceCode;
	}

	private function isUserAllowedForClient($user, Client $client): bool {
		$requiredGroups = $this->groupMapper->getGroupsByClientId($client->getId());
		if ($requiredGroups === []) {
			return true;
		}
		foreach ($requiredGroups as $requiredGroup) {
			foreach ($this->groupManager->getUserGroups($user) as $userGroup) {
				if ($requiredGroup->getGroupId() === $userGroup->getGID()) {
					return true;
				}
			}
		}
		return false;
	}

	private function storeConsent(string $userId, Client $client, string $scope): void {
		$existingConsent = $this->userConsentMapper->findByUserAndClient($userId, $client->getId());
		$consent = $existingConsent ?? new UserConsent();
		if ($existingConsent === null) {
			$consent->setUserId($userId);
			$consent->setClientId($client->getId());
			$consent->setCreatedAt($this->time->getTime());
		}
		$consent->setScopesGranted($scope);
		$consent->setUpdatedAt($this->time->getTime());
		$consent->setExpiresAt(null);
		$this->userConsentMapper->createOrUpdate($consent);
	}

	private function devicePage(
		string $mode,
		?string $userCode,
		?Client $client,
		?string $message,
		string $scope = '',
	): TemplateResponse {
		return new TemplateResponse('oidc', 'device', [
			'mode' => $mode,
			'userCode' => $userCode ?? '',
			'clientName' => $client?->getName() ?? '',
			'scope' => $scope,
			'message' => $message ?? '',
		], $this->userSession->isLoggedIn() ? TemplateResponse::RENDER_AS_USER : TemplateResponse::RENDER_AS_GUEST);
	}

	private function invalidClient(string $description, bool $basicAuthenticationAttempted = false): JSONResponse {
		$response = $this->oauthError(
			'invalid_client',
			$description,
			$basicAuthenticationAttempted ? Http::STATUS_UNAUTHORIZED : Http::STATUS_BAD_REQUEST,
		);
		if ($basicAuthenticationAttempted) {
			$response->addHeader('WWW-Authenticate', 'Basic realm="device_authorization"');
		}
		return $response;
	}

	private function oauthError(string $error, string $description, int $status = Http::STATUS_BAD_REQUEST): JSONResponse {
		$response = new JSONResponse(['error' => $error, 'error_description' => $description], $status);
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Pragma', 'no-cache');
		return $response;
	}
}
