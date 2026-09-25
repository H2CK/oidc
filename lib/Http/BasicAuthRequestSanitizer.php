<?php

declare(strict_types=1);

namespace OCA\OIDCIdentityProvider\Http;

use OC\AppFramework\Http\Request as CoreRequest;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Throwable;

final class BasicAuthRequestSanitizer
{
    private const TOKEN_ENDPOINT = '/apps/oidc/token';
    private const DEVICE_AUTHORIZATION_ENDPOINT = '/apps/oidc/device_authorization';
    private const INTROSPECTION_ENDPOINT = '/apps/oidc/introspect';

    /**
     * Custom, non-standard field name used to smuggle the original
     * Authorization header past Nextcloud Core.
     *
     * Only getPreservedAuthorizationHeader() is meant to read this. It must
     * never be a name Nextcloud Core or any other app/middleware recognizes
     * as an authentication credential.
     */
    private const PRESERVED_AUTHORIZATION_KEY = 'OIDC_PRESERVED_AUTHORIZATION';

    public function __construct(
        private IRequest $request,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Prevent Nextcloud Core from treating OAuth client_secret_basic
     * credentials as Nextcloud user credentials.
     *
     * This method is intentionally called for every request.
     */
    public function sanitize(): void
    {
        if (!$this->isOidcClientAuthenticationRequest()) {
            return;
        }

        /*
         * Nextcloud has already copied $_SERVER into its Request object.
         * Therefore changing only $_SERVER here is not sufficient.
         */
        $this->sanitizeNextcloudRequest();

        /*
         * Keep the PHP superglobal consistent as well, in case later code
         * accesses it directly.
         */
        $this->sanitizeServerArray($_SERVER);
    }

    /**
     * Retrieve the original Authorization header for a request previously
     * processed by sanitize(), even though it has been scrubbed from all
     * standard PHP/Nextcloud fields.
     *
     * Falls back to the normal Authorization header for requests that were
     * never sanitized (e.g. in tests, or if sanitize() was a no-op).
     */
    public static function getPreservedAuthorizationHeader(IRequest $request): string
    {
        $preserved = $request->server[self::PRESERVED_AUTHORIZATION_KEY] ?? null;
        if (is_string($preserved) && $preserved !== '') {
            return $preserved;
        }

        return $request->getHeader('Authorization');
    }

    private function isOidcClientAuthenticationRequest(): bool
    {
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            return false;
        }

        $requestUri = $this->request->getRequestUri();
        $path = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($path)) {
            return false;
        }

        $path = rtrim($path, '/');

        /*
         * Do not compare the complete URI because Nextcloud may be installed
         * below a web root and may use index.php in the URL:
         *
         * /apps/oidc/token
         * /index.php/apps/oidc/token
         * /nextcloud/index.php/apps/oidc/token
         */
        return str_ends_with($path, self::TOKEN_ENDPOINT)
            || str_ends_with($path, self::DEVICE_AUTHORIZATION_ENDPOINT)
            || str_ends_with($path, self::INTROSPECTION_ENDPOINT);
    }

    private function sanitizeNextcloudRequest(): void
    {
        /*
         * IRequest itself intentionally provides no mutating API.
         *
         * NC32+ uses OC\AppFramework\Http\Request with the
         * protected $items['server'] array.
         */
        if (!$this->request instanceof CoreRequest) {
            $this->logger->error(
                'OIDC: Cannot sanitize Basic authentication request: '
                . 'unexpected IRequest implementation ' . get_class($this->request)
            );
            return;
        }

        try {
            $property = new ReflectionProperty(CoreRequest::class, 'items');

            /** @var array $items */
            $items = $property->getValue($this->request);

            if (!isset($items['server']) || !is_array($items['server'])) {
                $this->logger->error(
                    'OIDC: Cannot sanitize Basic authentication request: '
                    . 'Request server data is unavailable'
                );
                return;
            }

            $this->sanitizeServerArray($items['server']);

            $property->setValue($this->request, $items);
        } catch (Throwable $e) {
            $this->logger->error(
                'OIDC: Failed to sanitize Basic authentication request',
                ['exception' => $e]
            );
        }
    }

    /**
     * @param array<string, mixed> $server
     */
    private function sanitizeServerArray(array &$server): void
    {
        /*
         * Determine the original Authorization value, regardless of which
         * of the possible SAPI-specific fields it currently lives in:
         * - HTTP_AUTHORIZATION            (most common; nginx/php-fpm etc.)
         * - REDIRECT_HTTP_AUTHORIZATION   (apache+php-cgi work around)
         * - PHP_AUTH_USER / PHP_AUTH_PW   (apache+mod_php, or already
         *                                  decoded by Nextcloud Core's own
         *                                  OC::handleAuthHeaders())
         *
         * Nextcloud Core decodes HTTP_AUTHORIZATION/REDIRECT_HTTP_AUTHORIZATION
         * into PHP_AUTH_USER/PHP_AUTH_PW itself (see OC::handleAuthHeaders()),
         * *before* any app is loaded. So by the time this method runs,
         * PHP_AUTH_USER may already be populated even if the client only
         * ever sent a raw Authorization header.
         */
        $authorization = null;

        if (!empty($server['HTTP_AUTHORIZATION'])) {
            $authorization = (string)$server['HTTP_AUTHORIZATION'];
        } elseif (!empty($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authorization = (string)$server['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (isset($server['PHP_AUTH_USER'])) {
            $username = (string)$server['PHP_AUTH_USER'];
            $password = (string)($server['PHP_AUTH_PW'] ?? '');
            $authorization = 'Basic ' . base64_encode($username . ':' . $password);
        }

        if ($authorization !== null) {
            $server[self::PRESERVED_AUTHORIZATION_KEY] = $authorization;
        }

        /*
         * These are ALL the values that must disappear, in every one of
         * their possible forms. It is not enough to remove PHP_AUTH_USER/
         * PHP_AUTH_PW: leaving HTTP_AUTHORIZATION (or its REDIRECT_ variant)
         * in place means any other code path in Nextcloud Core, in other
         * apps, or introduced in a future Nextcloud release that inspects
         * the raw Authorization header directly - rather than exclusively
         * relying on PHP_AUTH_USER/PHP_AUTH_PW - would still see and act on
         * the OAuth client credentials as if they were Nextcloud user
         * credentials.
         */
        unset(
            $server['PHP_AUTH_USER'],
            $server['PHP_AUTH_PW'],
            $server['HTTP_AUTHORIZATION'],
            $server['REDIRECT_HTTP_AUTHORIZATION']
        );
    }
}
