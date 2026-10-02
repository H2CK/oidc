<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Tests\Unit\Service;

use OCA\OIDCIdentityProvider\Exceptions\RedirectUriValidationException;
use OCA\OIDCIdentityProvider\Service\RedirectUriService;
use OCA\OIDCIdentityProvider\Service\RedirectUriUpgradeService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class RedirectUriUpgradeServiceTest extends TestCase {
    public function testStaticSameOriginAliasesRetainExactMatching(): void {
        $validator = new RedirectUriService(new NullLogger());
        $migration = new RedirectUriUpgradeService($validator);
        $this->assertSame(['https://rp.example/cb'], $migration->compatibilityAliases('https://RP.example/cb', false));
        $this->assertSame(['https://rp.example/'], $migration->compatibilityAliases('https://rp.example', false));
        $this->assertSame(['https://rp.example?x=1'], $migration->compatibilityAliases('https://rp.example/?x=1', false));
        $this->assertFalse($validator->matchRedirectUri('https://rp.example/', 'https://rp.example'));
        $this->assertFalse($validator->matchRedirectUri('https://rp.example/cb', 'https://RP.example/cb'));
    }

    public function testDcrExactUrisAreNotExpandedAndStaticPathWildcardIsPreserved(): void {
        $migration = new RedirectUriUpgradeService(new RedirectUriService(new NullLogger()));
        $this->assertSame([], $migration->compatibilityAliases('https://RP.example', true));
        $this->assertSame([], $migration->compatibilityAliases('https://rp.example/app/*', false));
    }

    public function testUnsafeLegacyPatternRequiresManualCorrection(): void {
        $migration = new RedirectUriUpgradeService(new RedirectUriService(new NullLogger()));
        $this->expectException(RedirectUriValidationException::class);
        $migration->compatibilityAliases('https://*/cb', false);
    }

    public function testEveryDcrWildcardIsRejected(): void {
        $migration = new RedirectUriUpgradeService(new RedirectUriService(new NullLogger()));
        $this->expectException(RedirectUriValidationException::class);
        $migration->compatibilityAliases('https://rp.example/app/*', true);
    }
}
