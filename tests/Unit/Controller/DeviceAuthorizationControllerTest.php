<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Timill
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Unit\Controller;

use OCA\OIDCIdentityProvider\Controller\DeviceAuthorizationController;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Db\ClientMapper;
use OCA\OIDCIdentityProvider\Db\DeviceCode;
use OCA\OIDCIdentityProvider\Db\DeviceCodeMapper;
use OCA\OIDCIdentityProvider\Db\GroupMapper;
use OCA\OIDCIdentityProvider\Db\UserConsent;
use OCA\OIDCIdentityProvider\Db\UserConsentMapper;
use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DeviceAuthorizationControllerTest extends TestCase {
	private IRequest $request;
	private ClientMapper $clientMapper;
	private DeviceCodeMapper $deviceCodeMapper;
	private GroupMapper $groupMapper;
	private UserConsentMapper $userConsentMapper;
	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private ISecureRandom $secureRandom;
	private ITimeFactory $time;
	private IURLGenerator $urlGenerator;
	private FormUrlencodedParameterParser $formParser;
	private DeviceAuthorizationController $controller;
	private string $contentType = 'application/x-www-form-urlencoded';
	private string $authorizationHeader = '';
	/** @var array<string,list<string>>|null */
	private ?array $rawParameters = [];

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->clientMapper = $this->createMock(ClientMapper::class);
		$this->deviceCodeMapper = $this->createMock(DeviceCodeMapper::class);
		$this->groupMapper = $this->createMock(GroupMapper::class);
		$this->userConsentMapper = $this->createMock(UserConsentMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->secureRandom = $this->createMock(ISecureRandom::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->formParser = $this->createMock(FormUrlencodedParameterParser::class);

		$this->request->method('getHeader')->willReturnCallback(fn (string $name): string => match ($name) {
			'Content-Type' => $this->contentType,
			'Authorization' => $this->authorizationHeader,
			default => '',
		});
		$this->formParser->method('readSelectedParameters')->willReturnCallback(fn (): ?array => $this->rawParameters);

		$this->controller = new DeviceAuthorizationController(
			'oidc',
			$this->request,
			$this->clientMapper,
			$this->deviceCodeMapper,
			$this->groupMapper,
			$this->userConsentMapper,
			$this->userSession,
			$this->groupManager,
			$this->secureRandom,
			$this->time,
			$this->urlGenerator,
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
			$this->formParser,
		);
	}

	public function testPublicClientCanStartDeviceAuthorization(): void {
		$client = $this->createClient('public');
		$this->rawParameters = [
			'client_id' => ['device-client'],
			'client_secret' => [],
			'scope' => ['openid profile email'],
		];
		$this->clientMapper->expects($this->once())
			->method('getByIdentifier')
			->with('device-client')
			->willReturn($client);
		$this->secureRandom->expects($this->exactly(2))
			->method('generate')
			->willReturnOnConsecutiveCalls('device-code', 'ABCD2345');
		$this->deviceCodeMapper->expects($this->once())
			->method('findByUserCode')
			->with('ABCD2345')
			->willReturn(null);
		$this->time->method('getTime')->willReturn(1_000);
		$this->urlGenerator->expects($this->once())
			->method('linkToRouteAbsolute')
			->with('oidc.DeviceAuthorization.verify', [])
			->willReturn('https://cloud.example/apps/oidc/device');
		$this->deviceCodeMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (DeviceCode $code): bool {
				$this->assertSame(1, $code->getClientId());
				$this->assertSame(hash('sha512', 'device-code'), $code->getHashedDeviceCode());
				$this->assertSame(hash('sha512', 'ABCD2345'), $code->getHashedUserCode());
				$this->assertSame('openid profile email', $code->getScope());
				$this->assertSame(1_600, $code->getExpiresAt());
				$this->assertSame(DeviceCode::STATUS_PENDING, $code->getStatus());
				return true;
			}));

		$response = $this->controller->authorize('device-client', 'openid profile email');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('device-code', $response->getData()['device_code']);
		$this->assertSame('ABCD-2345', $response->getData()['user_code']);
		$this->assertSame('https://cloud.example/apps/oidc/device', $response->getData()['verification_uri']);
		$this->assertSame('https://cloud.example/apps/oidc/device?user_code=ABCD-2345', $response->getData()['verification_uri_complete']);
		$this->assertSame(600, $response->getData()['expires_in']);
		$this->assertSame(5, $response->getData()['interval']);
		$this->assertSame('no-store', $response->getHeaders()['Cache-Control']);
	}

	public function testConfidentialClientRequiresCorrectSecret(): void {
		$this->rawParameters = [
			'client_id' => ['device-client'],
			'client_secret' => [],
			'scope' => ['openid'],
		];
		$this->clientMapper->method('getByIdentifier')->willReturn($this->createClient('confidential'));

		$response = $this->controller->authorize('device-client', 'openid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_client', $response->getData()['error']);
	}

	public function testMalformedBasicAuthenticationIsRejected(): void {
		$this->authorizationHeader = 'Basic definitely-not-base64';
		$this->rawParameters = [
			'client_id' => [],
			'client_secret' => [],
			'scope' => ['openid'],
		];

		$response = $this->controller->authorize(null, 'openid');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('invalid_client', $response->getData()['error']);
		$this->assertSame('Basic realm="device_authorization"', $response->getHeaders()['WWW-Authenticate']);
	}

	public function testDisallowedScopeIsRejected(): void {
		$this->rawParameters = [
			'client_id' => ['device-client'],
			'client_secret' => [],
			'scope' => ['openid admin'],
		];
		$this->clientMapper->method('getByIdentifier')->willReturn($this->createClient('public'));

		$response = $this->controller->authorize('device-client', 'openid admin');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_scope', $response->getData()['error']);
	}

	public function testLoggedInUserCanApprovePendingCode(): void {
		$deviceCode = new DeviceCode();
		$deviceCode->setId(7);
		$deviceCode->setClientId(1);
		$deviceCode->setScope('openid profile email');
		$deviceCode->setExpiresAt(1_600);
		$deviceCode->setStatus(DeviceCode::STATUS_PENDING);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->deviceCodeMapper->method('findByUserCode')->with('ABCD-2345')->willReturn($deviceCode);
		$this->time->method('getTime')->willReturn(1_000);
		$this->userSession->method('getUser')->willReturn($user);
		$this->clientMapper->method('getByUid')->with(1)->willReturn($this->createClient('public'));
		$this->groupMapper->method('getGroupsByClientId')->with(1)->willReturn([]);
		$this->deviceCodeMapper->expects($this->once())
			->method('markApproved')
			->with($deviceCode, 'alice')
			->willReturn(true);
		$this->userConsentMapper->method('findByUserAndClient')->with('alice', 1)->willReturn(null);
		$this->userConsentMapper->expects($this->once())
			->method('createOrUpdate')
			->with($this->callback(function (UserConsent $consent): bool {
				$this->assertSame('alice', $consent->getUserId());
				$this->assertSame(1, $consent->getClientId());
				$this->assertSame('openid profile email', $consent->getScopesGranted());
				return true;
			}));

		$response = $this->controller->approve('ABCD-2345');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	private function createClient(string $type): Client {
		$client = new Client(
			'Device client',
			[],
			'RS256',
			$type,
			'code',
			'opaque',
			'openid profile email offline_access',
		);
		$client->setId(1);
		$client->setClientIdentifier('device-client');
		$client->setSecret('secret');
		return $client;
	}
}
