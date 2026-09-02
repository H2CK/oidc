<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Thorsten Jagel
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\OIDCIdentityProvider\Controller;

use OCA\OIDCIdentityProvider\Service\SessionManagementService;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoSameSiteCookieRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

class SessionController extends ApiController {
    public function __construct(
        string $appName,
        IRequest $request,
        private SessionManagementService $sessionManagementService,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoSameSiteCookieRequired]
    #[UseSession]
    #[PublicPage]
    public function checkSessionIframe(): DataDisplayResponse {
        $statusUrl = json_encode($this->urlGenerator->linkToRouteAbsolute('oidc.Session.check', []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>OIDC Session Management</title></head><body><script>'
            . 'window.addEventListener("message",async function(e){'
            . 'if(!e.source||typeof e.origin!=="string"||typeof e.data!=="string"){return;}'
            . 'var p=e.data.indexOf(" ");if(p<=0){e.source.postMessage("error",e.origin);return;}'
            . 'var c=e.data.substring(0,p),s=e.data.substring(p+1);'
            . 'try{var u=' . $statusUrl . '+"?client_id="+encodeURIComponent(c)+"&session_state="+encodeURIComponent(s)+"&origin="+encodeURIComponent(e.origin);'
            . 'var r=await fetch(u,{credentials:"include",cache:"no-store"});var j=await r.json();'
            . 'e.source.postMessage(j.status||"error",e.origin);}catch(x){e.source.postMessage("error",e.origin);}'
            . '});</script></body></html>';
        $response = new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->addHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; connect-src 'self'; frame-ancestors *");
        return $response;
    }

    #[NoCSRFRequired]
    #[NoSameSiteCookieRequired]
    #[UseSession]
    #[PublicPage]
    public function check(string $client_id = '', string $session_state = '', string $origin = ''): JSONResponse {
        $status = $this->sessionManagementService->checkSessionState($client_id, $origin, $session_state);
        $response = new JSONResponse(['status' => $status]);
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
}
