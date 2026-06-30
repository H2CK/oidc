<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;

class FormPostResponse extends Response {
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private string $redirectUri,
        private array $params
    ) {
        parent::__construct();

        $this->setStatus(Http::STATUS_OK);
        $this->addHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->addHeader('Cache-Control', 'no-store');
        $this->addHeader('Pragma', 'no-cache');
        $this->addHeader('Referrer-Policy', 'no-referrer');
        $this->addHeader(
            'Content-Security-Policy',
            "default-src 'none'; base-uri 'none'; form-action *; script-src 'unsafe-inline'; frame-ancestors 'none'"
        );
    }

    public function render(): string {
        $inputs = '';
        foreach ($this->params as $name => $value) {
            if ($value === null) {
                continue;
            }

            $inputs .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                $this->escape((string)$name),
                $this->escape((string)$value)
            );
        }

        return '<!DOCTYPE html>'
            . '<html><head><meta charset="utf-8"><title>Authorization Response</title></head>'
            . '<body onload="document.forms[0].submit()">'
            . '<form method="post" action="' . $this->escape($this->redirectUri) . '">'
            . $inputs
            . '<noscript><button type="submit">Continue</button></noscript>'
            . '</form><script>document.forms[0].submit();</script></body></html>';
    }

    private function escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
