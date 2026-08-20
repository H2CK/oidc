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
    private const INTROSPECTION_ENDPOINT = '/apps/oidc/introspect';

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
         * Apache/mod_php sometimes exposes Basic auth only through
         * PHP_AUTH_USER/PHP_AUTH_PW and does not retain HTTP_AUTHORIZATION.
         *
         * Preserve an Authorization header before removing the PHP_AUTH_*
         * fields, otherwise the OIDC controller could lose the credentials.
         */
        if (
            empty($server['HTTP_AUTHORIZATION'])
            && isset($server['PHP_AUTH_USER'])
        ) {
            $username = (string)$server['PHP_AUTH_USER'];
            $password = (string)($server['PHP_AUTH_PW'] ?? '');

            $server['HTTP_AUTHORIZATION']
                = 'Basic ' . base64_encode($username . ':' . $password);
        }

        /*
         * These are the values that must disappear.
         *
         * Nextcloud's HTTP Basic user authentication consumes these values.
         */
        unset(
            $server['PHP_AUTH_USER'],
            $server['PHP_AUTH_PW']
        );
    }
}

