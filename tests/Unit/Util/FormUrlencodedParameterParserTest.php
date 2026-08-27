<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2026 Thorsten Jagel <dev@jagel.net>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OIDCIdentityProvider\Tests\Unit\Util;

use OCA\OIDCIdentityProvider\Util\FormUrlencodedParameterParser;
use PHPUnit\Framework\TestCase;

class FormUrlencodedParameterParserTest extends TestCase {
    private FormUrlencodedParameterParser $parser;

    protected function setUp(): void {
        parent::setUp();
        $this->parser = new FormUrlencodedParameterParser();
    }

    public function testPreservesRepeatedResourceAndAudienceParameters(): void {
        $result = $this->parser->parseSelectedParameters(
            'resource=https%3A%2F%2Fapi-a.example&resource=https%3A%2F%2Fapi-b.example&audience=backend-a&audience=backend-b',
            ['resource', 'audience']
        );

        $this->assertSame([
            'https://api-a.example',
            'https://api-b.example',
        ], $result['resource']);
        $this->assertSame(['backend-a', 'backend-b'], $result['audience']);
    }

    public function testRecognizesPercentEncodedParameterName(): void {
        $result = $this->parser->parseSelectedParameters(
            'res%6Furce=https%3A%2F%2Fapi-a.example&resource=https%3A%2F%2Fapi-b.example',
            ['resource', 'audience']
        );

        $this->assertSame([
            'https://api-a.example',
            'https://api-b.example',
        ], $result['resource']);
        $this->assertSame([], $result['audience']);
    }

    public function testDoesNotSplitEncodedAmpersandInsideValue(): void {
        $result = $this->parser->parseSelectedParameters(
            'resource=https%3A%2F%2Fapi.example%2Fpath%3Fa%3D1%26b%3D2',
            ['resource']
        );

        $this->assertSame([
            'https://api.example/path?a=1&b=2',
        ], $result['resource']);
    }

    public function testFormUrlencodedPlusIsDecodedAsSpace(): void {
        $result = $this->parser->parseSelectedParameters(
            'audience=backend+service',
            ['audience']
        );

        $this->assertSame(['backend service'], $result['audience']);
    }

    public function testIgnoresUnselectedParametersAndPreservesEmptyValue(): void {
        $result = $this->parser->parseSelectedParameters(
            'scope=openid&resource=&subject_token=abc',
            ['resource', 'audience']
        );

        $this->assertSame([''], $result['resource']);
        $this->assertSame([], $result['audience']);
    }
}
