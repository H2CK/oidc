<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Patrick Bender
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Integration;

use OCA\OIDCIdentityProvider\AppInfo\Application;
use OCA\OIDCIdentityProvider\Db\AccessToken;
use OCA\OIDCIdentityProvider\Db\AccessTokenMapper;
use OCA\OIDCIdentityProvider\Exceptions\AccessTokenNotFoundException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Server;

#[\PHPUnit\Framework\Attributes\Group(name: 'DB')]
class AccessTokenMapperIntegrationTest extends \Test\TestCase {
	private AccessTokenMapper $mapper;
	private IAppConfig $appConfig;

	/** @var \OCP\AppFramework\App */
    private $app;
	
	private ?string $previousRefreshExpireTime = null;

	protected function setUp(): void {
		parent::setUp();

		// Load the app to ensure its services are registered
        $this->app = new \OCP\AppFramework\App('oidc');
        $appContainer = $this->app->getContainer();
		
		$this->mapper = Server::get(AccessTokenMapper::class);
		
		$this->appConfig = $appContainer->get(IAppConfig::class);
		$this->previousRefreshExpireTime = $this->appConfig->getAppValueString(
			Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME,
			Application::DEFAULT_REFRESH_EXPIRE_TIME
		);
	}

	protected function tearDown(): void {
		if ($this->previousRefreshExpireTime !== null) {
			$this->appConfig->setAppValueString(
				Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME,
				$this->previousRefreshExpireTime
			);
		}
		parent::tearDown();
	}

	private function insertToken(int $refreshed): AccessToken {
		$entity = new AccessToken();
		$entity->setClientId(987654);
		$entity->setUserId('alice');
		$entity->setScope('openid profile email');
		$entity->setHashedCode(hash('sha512', 'test-refresh-' . bin2hex(random_bytes(8))));
		$entity->setAccessToken('test-access-' . bin2hex(random_bytes(8)));
		$entity->setCreated($refreshed);
		$entity->setRefreshed($refreshed);
		$entity->setExpiresAt($refreshed + 60);
		$entity->setNonce('');
		$entity->setResource('');
		$entity->setCodeChallenge('');
		$entity->setCodeChallengeMethod('');
		$entity->setIdTokenClaims(null);
		$entity->setUserinfoClaims(null);
		$entity->setSid(null);
		return $this->mapper->insert($entity);
	}

	/**
	 * Regression test for the "never" cleanup bug: with
	 * refresh_expire_time = 'never', a row that has not been refreshed in
	 * a long time must survive cleanUp() - "never" must not silently fall
	 * back to the (much shorter) access-token expire_time.
	 */
	public function testNeverExpiringRefreshTokenSurvivesCleanUp(): void {
		$this->appConfig->setAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, '900');
		$this->appConfig->setAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, 'never');

		$staleRefreshedAt = time() - 100000; // far older than expire_time
		$entity = $this->insertToken($staleRefreshedAt);

		try {
			$this->mapper->cleanUp();
			$stored = $this->mapper->getById($entity->getId());
			$this->assertSame($entity->getId(), $stored->getId());
		} finally {
			$this->safeDelete($entity);
		}
	}

	/**
	 * With a concrete refresh_expire_time, cleanUp() must still delete rows
	 * that have not been refreshed within that window.
	 */
	public function testTokenIsCleanedUpAfterConcreteRefreshExpiry(): void {
		$this->appConfig->setAppValueString(Application::APP_CONFIG_DEFAULT_EXPIRE_TIME, '10');
		$this->appConfig->setAppValueString(Application::APP_CONFIG_DEFAULT_REFRESH_EXPIRE_TIME, '10');

		$staleRefreshedAt = time() - 100;
		$entity = $this->insertToken($staleRefreshedAt);

		$this->mapper->cleanUp();

		$this->expectException(AccessTokenNotFoundException::class);
		$this->mapper->getById($entity->getId());
	}

	private function safeDelete(AccessToken $entity): void {
		try {
			$this->mapper->delete($this->mapper->getById($entity->getId()));
		} catch (AccessTokenNotFoundException $e) {
			// Already gone - nothing to clean up.
		}
	}
}
