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
    private string $scriptNonce;
    private string $formActionSource;

    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        private string $redirectUri,
        private array $params
    ) {
        parent::__construct();

        $this->scriptNonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $this->formActionSource = $this->buildFormActionSource($redirectUri);

        $this->setStatus(Http::STATUS_OK);
        $this->addHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->addHeader('Cache-Control', 'no-store');
        $this->addHeader('Pragma', 'no-cache');
        $this->addHeader('Referrer-Policy', 'no-referrer');
        $this->addHeader('X-Content-Type-Options', 'nosniff');
        $this->addHeader(
            'Content-Security-Policy',
            "default-src 'none'; base-uri 'none'; form-action " . $this->formActionSource
                . "; script-src 'nonce-" . $this->scriptNonce . "'; frame-ancestors 'none'"
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
            . '<body>'
            . '<form method="post" action="' . $this->escape($this->redirectUri) . '">'
            . $inputs
            . '<noscript><button type="submit">Continue</button></noscript>'
            . '</form><script nonce="' . $this->escape($this->scriptNonce) . '">document.forms[0].submit();</script></body></html>';
    }

    private function escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function buildFormActionSource(string $redirectUri): string {
        $parts = parse_url($redirectUri);
        if ($parts === false || !isset($parts['scheme'])
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*$/', (string)$parts['scheme']) !== 1) {
            return "'none'";
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $scheme . ':';
        }
        if (!isset($parts['host']) || preg_match('/[\s;\x00-\x1f\x7f]/', (string)$parts['host']) === 1) {
            return "'none'";
        }

        $host = (string)$parts['host'];
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }
        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int)$parts['port'];
        }
        return $origin;
    }
}
