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
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\NoSameSiteCookieRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class SessionController extends ApiController {
    public function __construct(
        string $appName,
        IRequest $request,
        private SessionManagementService $sessionManagementService,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoSameSiteCookieRequired]
    #[PublicPage]
    public function checkSessionIframe(): DataDisplayResponse {
        // The dedicated oidc_opbs cookie is intentionally JavaScript-readable
        // and contains only random OP browser state (never authentication data).
        // Reading it for every message means an already loaded iframe observes
        // login/logout rotations immediately without network polling.
        $cookieName = json_encode(
            SessionManagementService::OP_BROWSER_STATE_COOKIE,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $originBindingJwk = json_encode(
            $this->sessionManagementService->getOriginBindingJwk(),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>OIDC Session Management</title></head><body><script>'
            . 'const opbsName=' . $cookieName . ';const bindingJwk=' . $originBindingJwk . ';let observedOpbs=false;'
            . 'const subtle=(globalThis.crypto&&crypto.subtle)?crypto.subtle:null;'
            . 'const bindingKey=subtle?subtle.importKey("jwk",bindingJwk,{name:"RSASSA-PKCS1-v1_5",hash:"SHA-256"},false,["verify"]):Promise.resolve(null);'
            . 'function readOpbs(){var p=document.cookie.split("; "),n=opbsName+"=";for(var i=0;i<p.length;i++){if(p[i].startsWith(n)){try{var v=decodeURIComponent(p[i].substring(n.length));return /^[A-Za-z0-9]{64}$/.test(v)?v:null;}catch(x){return null;}}}return null;}'
            . 'function b64uDecode(v){try{v=v.replace(/-/g,"+").replace(/_/g,"/");while(v.length%4){v+="=";}return atob(v);}catch(x){return null;}}'
            . 'function b64uBytes(v){var s=b64uDecode(v);if(s===null){return null;}var b=new Uint8Array(s.length);for(var i=0;i<s.length;i++){b[i]=s.charCodeAt(i);}return b;}'
            . 'async function sha256(v){if(!subtle){throw new Error("WebCrypto unavailable");}var b=await subtle.digest("SHA-256",new TextEncoder().encode(v));return Array.from(new Uint8Array(b),function(x){return x.toString(16).padStart(2,"0");}).join("");}'
            . 'async function validBinding(c,o,v){if(!subtle){return false;}var sig=b64uBytes(v);if(sig===null){return false;}try{var k=await bindingKey;return k!==null&&await subtle.verify({name:"RSASSA-PKCS1-v1_5"},k,sig,new TextEncoder().encode(c+"\n"+o));}catch(x){return false;}}'
            . 'if(readOpbs()!==null){observedOpbs=true;}'
            . 'window.addEventListener("message",async function(e){'
            . 'if(!e.source||typeof e.origin!=="string"||typeof e.data!=="string"){return;}'
            . 'var p=e.data.lastIndexOf(" ");if(p<=0){e.source.postMessage("error",e.origin);return;}'
            . 'var c=e.data.substring(0,p),s=e.data.substring(p+1),a=s.split("."),opbs=readOpbs();'
            . 'if(a.length!==5||a[0]!=="3"){e.source.postMessage("error",e.origin);return;}'
            . 'var o=b64uDecode(a[1]);if(o===null||o!==e.origin||!/^[a-fA-F0-9]{64}$/.test(a[2])||!/^[A-Za-z0-9]{16,128}$/.test(a[3])||!/^[A-Za-z0-9_-]{64,2048}$/.test(a[4])){e.source.postMessage("error",e.origin);return;}'
            . 'if(!(await validBinding(c,e.origin,a[4]))){e.source.postMessage("error",e.origin);return;}'
            . 'if(opbs===null){e.source.postMessage(observedOpbs?"changed":"error",e.origin);return;}observedOpbs=true;'
            . 'try{var h=await sha256(c+" "+e.origin+" "+opbs+" "+a[3]);e.source.postMessage(h===a[2].toLowerCase()?"unchanged":"changed",e.origin);}catch(x){e.source.postMessage("error",e.origin);}'
            . '});</script></body></html>';
        $response = new DataDisplayResponse($html, Http::STATUS_OK, ['Content-Type' => 'text/html; charset=utf-8']);
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Content-Security-Policy', "default-src 'none'; script-src 'unsafe-inline'; frame-ancestors *; base-uri 'none'; form-action 'none'");
        return $response;
    }

    // Kept for backwards compatibility/diagnostics. Normal Session Management
    // iframe polling no longer calls this endpoint. Rate limiting prevents it
    // from becoming an unauthenticated DB-amplification surface.
    #[NoCSRFRequired]
    #[NoSameSiteCookieRequired]
    #[PublicPage]
    #[AnonRateLimit(limit: 120, period: 60)]
    public function check(string $client_id = '', string $session_state = '', string $origin = ''): JSONResponse {
        $status = $this->sessionManagementService->checkSessionState($client_id, $origin, $session_state);
        $response = new JSONResponse(['status' => $status]);
        $response->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
}
