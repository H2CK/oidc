from __future__ import annotations

import base64
import hashlib
import json
import os
import secrets
import time
from pathlib import Path
from typing import Any
from urllib.parse import urlencode

import httpx
from selenium import webdriver
from selenium.common.exceptions import NoSuchElementException, WebDriverException
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait


BASE_URL = os.environ.get("OAUTH_BASE_URL", "https://nextcloud-proxy:8443").rstrip("/")
DISCOVERY_URL = os.environ.get(
    "OAUTH_DISCOVERY_URL", f"{BASE_URL}/index.php/.well-known/openid-configuration"
)
CLIENT_ID = os.environ.get("OAUTH_CLIENT_ID", "oauth-conformance-client-000000000001")
CLIENT_SECRET = os.environ.get("OAUTH_CLIENT_SECRET", "oauth-conformance-secret-0000000001")
SECOND_CLIENT_ID = os.environ.get("OAUTH_SECOND_CLIENT_ID", "oauth-conformance-client-000000000002")
SECOND_CLIENT_SECRET = os.environ.get("OAUTH_SECOND_CLIENT_SECRET", "oauth-conformance-secret-0000000002")
REDIRECT_URI = os.environ.get("OAUTH_CALLBACK_URI", "https://oauth-callback:9444/callback")
USER = os.environ.get("OIDC_TEST_USER", "oidc-test-user")
PASSWORD = os.environ.get("OIDC_TEST_PASSWORD", "oidc-test-password")
SELENIUM_REMOTE_URL = os.environ.get("SELENIUM_REMOTE_URL", "http://127.0.0.1:4444/wd/hub")
RESULTS_DIR = Path(os.environ.get("OAUTH_RESULTS_DIR", "oauth-results"))
CALLBACK_FILE = RESULTS_DIR / "callback.json"
CA_CERT = os.environ.get("OAUTH_CA_CERT", ".github/oauth-tests/certs/ca.crt")

TOKEN_EXCHANGE_GRANT = "urn:ietf:params:oauth:grant-type:token-exchange"
ACCESS_TOKEN_TYPE = "urn:ietf:params:oauth:token-type:access_token"
DEVICE_GRANT = "urn:ietf:params:oauth:grant-type:device_code"


def env_true(name: str) -> bool:
    return os.environ.get(name, "false").strip().lower() in {"1", "true", "yes", "on"}


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode("ascii")


def pkce_pair() -> tuple[str, str]:
    verifier = b64url(secrets.token_bytes(48))
    challenge = b64url(hashlib.sha256(verifier.encode("ascii")).digest())
    return verifier, challenge


class OAuthHarness:
    def __init__(self) -> None:
        self.http = httpx.Client(verify=CA_CERT, timeout=20.0, follow_redirects=False)
        discovery = self.http.get(DISCOVERY_URL)
        discovery.raise_for_status()
        self.metadata: dict[str, Any] = discovery.json()
        self.browser: webdriver.Remote | None = None

    def close(self) -> None:
        if self.browser:
            self.browser.quit()
        self.http.close()

    def _browser(self) -> webdriver.Remote:
        if self.browser:
            return self.browser
        options = webdriver.ChromeOptions()
        options.add_argument("--headless=new")
        options.add_argument("--no-sandbox")
        options.add_argument("--disable-dev-shm-usage")
        options.set_capability("acceptInsecureCerts", True)
        self.browser = webdriver.Remote(command_executor=SELENIUM_REMOTE_URL, options=options)
        self.browser.set_page_load_timeout(30)
        return self.browser

    def authorize(
        self,
        *,
        scopes: str = "openid profile",
        client_id: str = CLIENT_ID,
        redirect_uri: str = REDIRECT_URI,
        code_challenge: str | None = None,
        code_challenge_method: str | None = None,
        extra: dict[str, str] | None = None,
    ) -> dict[str, str]:
        RESULTS_DIR.mkdir(parents=True, exist_ok=True)
        CALLBACK_FILE.unlink(missing_ok=True)
        state = secrets.token_urlsafe(20)
        params = {
            "response_type": "code",
            "client_id": client_id,
            "redirect_uri": redirect_uri,
            "scope": scopes,
            "state": state,
            "nonce": secrets.token_urlsafe(20),
        }
        if code_challenge:
            params["code_challenge"] = code_challenge
            params["code_challenge_method"] = code_challenge_method or "S256"
        if extra:
            params.update(extra)

        browser = self._browser()
        url = f"{self.metadata['authorization_endpoint']}?{urlencode(params)}"
        browser.get(url)
        self._login_if_needed(browser)
        self._approve_if_needed(browser)

        deadline = time.time() + 35
        while time.time() < deadline:
            if CALLBACK_FILE.exists():
                payload = json.loads(CALLBACK_FILE.read_text(encoding="utf-8"))
                query = payload.get("query", {})
                if query.get("state") != state:
                    raise AssertionError(f"callback state mismatch: {query!r}")
                return query
            time.sleep(0.25)

        raise AssertionError(
            f"authorization did not reach callback; current browser URL={browser.current_url!r}"
        )

    def _login_if_needed(self, browser: webdriver.Remote) -> None:
        try:
            user = browser.find_element(By.ID, "user")
        except NoSuchElementException:
            try:
                user = browser.find_element(By.NAME, "user")
            except NoSuchElementException:
                return
        user.clear()
        user.send_keys(USER)
        password = browser.find_element(By.ID, "password")
        password.clear()
        password.send_keys(PASSWORD)
        browser.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
        WebDriverWait(browser, 15).until(lambda d: "login" not in d.current_url.lower())

    def _approve_if_needed(self, browser: webdriver.Remote) -> None:
        # Normally disabled by allow_user_settings=no. Keep a small fallback for
        # installations that still display a consent page.
        for text in ("Allow", "Authorize", "Grant", "Continue", "Yes"):
            try:
                button = browser.find_element(
                    By.XPATH,
                    f"//button[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '{text.lower()}')]",
                )
                button.click()
                return
            except (NoSuchElementException, WebDriverException):
                continue

    def token(self, data: dict[str, str], *, client_id: str = CLIENT_ID, client_secret: str = CLIENT_SECRET,
              auth_method: str = "basic") -> httpx.Response:
        endpoint = self.metadata["token_endpoint"]
        form = dict(data)
        if auth_method == "basic":
            return self.http.post(endpoint, data=form, auth=(client_id, client_secret))
        if auth_method == "post":
            form.update({"client_id": client_id, "client_secret": client_secret})
            return self.http.post(endpoint, data=form)
        if auth_method == "none":
            return self.http.post(endpoint, data=form)
        raise ValueError(auth_method)

    def authorization_code(
        self,
        *,
        scopes: str = "openid profile",
        client_id: str = CLIENT_ID,
        code_challenge: str | None = None,
        code_challenge_method: str | None = None,
    ) -> str:
        query = self.authorize(
            scopes=scopes,
            client_id=client_id,
            code_challenge=code_challenge,
            code_challenge_method=code_challenge_method,
        )
        assert "error" not in query, query
        assert query.get("code"), query
        return query["code"]

    def exchange_code(
        self,
        code: str,
        *,
        client_id: str = CLIENT_ID,
        client_secret: str = CLIENT_SECRET,
        redirect_uri: str = REDIRECT_URI,
        verifier: str | None = None,
        auth_method: str = "basic",
    ) -> httpx.Response:
        data = {
            "grant_type": "authorization_code",
            "code": code,
            "redirect_uri": redirect_uri,
        }
        if verifier is not None:
            data["code_verifier"] = verifier
        return self.token(data, client_id=client_id, client_secret=client_secret, auth_method=auth_method)

    def issue_tokens(self, scopes: str = "openid profile") -> dict[str, Any]:
        code = self.authorization_code(scopes=scopes)
        response = self.exchange_code(code)
        assert response.status_code == 200, response.text
        return response.json()

    def introspect(self, token: str, *, client_id: str = CLIENT_ID, client_secret: str = CLIENT_SECRET) -> httpx.Response:
        endpoint = self.metadata.get("introspection_endpoint") or f"{BASE_URL}/index.php/apps/oidc/introspect"
        return self.http.post(endpoint, data={"token": token}, auth=(client_id, client_secret))
