<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 OIDC Identity Provider contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Controller\DynamicRegistrationController;
use OCA\OIDCIdentityProvider\Db\Client;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StandardsRegressionTest extends TestCase {
    public function testNativeLoopbackPortExceptionKeepsHostPathAndQueryExact(): void {
        $service = new RedirectUriService($this->createMock(LoggerInterface::class));
        foreach (['127.0.0.1', '[::1]'] as $host) {
            $registered = 'http://' . $host . ':1234/callback?state=exact';
            $this->assertTrue($service->matchRedirectUri('http://' . $host . ':5678/callback?state=exact', $registered, true));
            $this->assertFalse($service->matchRedirectUri('http://' . $host . ':5678/callback?state=exact', $registered, false));
            $this->assertFalse($service->matchRedirectUri('http://' . $host . ':5678/other?state=exact', $registered, true));
            $this->assertFalse($service->matchRedirectUri('http://' . $host . ':5678/callback?state=other', $registered, true));
            $this->assertFalse($service->matchRedirectUri('http://127.0.0.2:5678/callback?state=exact', $registered, true));
            $this->assertFalse($service->matchRedirectUri('http://' . $host . ':5678/callback/../other?state=exact', $registered, true));
            $this->assertTrue($service->matchRedirectUri('http://' . $host . ':5678/callback', 'http://' . $host . ':*/callback'));
            $this->assertFalse($service->matchRedirectUri('http://' . $host . ':5678/other', 'http://' . $host . ':*/callback'));
        }
    }

    public function testStoredGrantTypesRestrictDeviceAndRefreshSeparately(): void {
        $client = new Client();
        $client->setDcr(true);
        $client->setRegisteredGrantTypes(['authorization_code']);
        $this->assertTrue($client->allowsGrantType('authorization_code'));
        $this->assertFalse($client->allowsGrantType('refresh_token'));
        $this->assertFalse($client->allowsGrantType('urn:ietf:params:oauth:grant-type:device_code'));
        $client->setRegisteredGrantTypes(['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token']);
        $this->assertFalse($client->allowsGrantType('authorization_code'));
        $this->assertTrue($client->allowsGrantType('refresh_token'));
    }

    public function testPublicWebClientDoesNotGetTheNativePortException(): void {
        $client = new Client(type: 'public');
        $this->assertTrue($client->isNativeApplication());
        $client->setApplicationType('web');
        $this->assertFalse($client->isNativeApplication());
        $client->setApplicationType('native');
        $this->assertTrue($client->isNativeApplication());
    }

    public function testResponseTypesUseRegisteredCombinationsAndIgnoreOrder(): void {
        $client = new Client();
        $client->setRegisteredResponseTypes(['code id_token']);
        $this->assertTrue($client->supportsResponseType('id_token code'));
        $this->assertFalse($client->supportsResponseType('code'));
        $this->assertFalse($client->supportsResponseType('code id_token token'));
    }

    public function testRegistrationRejectsInconsistentAndUnknownGrants(): void {
        $controller = (new \ReflectionClass(DynamicRegistrationController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'normalizeFlowMetadata');
        foreach ([
            [['code'], ['implicit']],
            [['id_token'], ['authorization_code']],
            [[], ['authorization_code']],
            [['code'], ['password']],
            [['code code'], ['authorization_code']],
        ] as [$responses, $grants]) {
            $response = $method->invoke($controller, $responses, $grants);
            $this->assertInstanceOf(JSONResponse::class, $response);
            $this->assertSame('invalid_client_metadata', $response->getData()['error']);
        }
        $device = 'urn:ietf:params:oauth:grant-type:device_code';
        $this->assertSame([[], [$device, 'refresh_token']], $method->invoke($controller, [], [$device, 'refresh_token']));
    }
}
