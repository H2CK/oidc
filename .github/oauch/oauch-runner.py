#!/usr/bin/env python3
"""Best-effort UI driver for a *self-hosted* OAuch instance.

OAuch documents that its testing process is not fully automated. This runner is
therefore intentionally defensive: it configures the common profile form using
labels/names rather than brittle absolute selectors, logs in to Nextcloud when
needed, tries to advance stalled tests, and always saves page/screenshot
artifacts for diagnosis.
"""

from __future__ import annotations

import json
import os
import re
import time
from pathlib import Path

import httpx
from selenium import webdriver
from selenium.common.exceptions import NoSuchElementException, WebDriverException
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select, WebDriverWait

RESULTS = Path(os.environ.get("OAUCH_RESULTS_DIR", "oauch-results"))
RESULTS.mkdir(parents=True, exist_ok=True)
OAUCH_URL = os.environ.get("OAUCH_URL", "https://oauch.io/")
PUBLIC_HEALTH_URL = os.environ.get("OAUCH_HEALTH_URL", "https://127.0.0.1:9443/")
DISCOVERY = os.environ.get(
    "OAUTH_DISCOVERY_URL", "https://nextcloud-proxy:8443/index.php/.well-known/openid-configuration"
)
CA = os.environ.get("OAUTH_CA_CERT", ".github/oauth-tests/certs/ca.crt")
CLIENT_ID = os.environ["OAUCH_CLIENT_ID"]
CLIENT_SECRET = os.environ["OAUCH_CLIENT_SECRET"]
USER = os.environ.get("OIDC_TEST_USER", "oidc-test-user")
PASSWORD = os.environ.get("OIDC_TEST_PASSWORD", "oidc-test-password")
SELENIUM = os.environ.get("SELENIUM_REMOTE_URL", "http://127.0.0.1:4444/wd/hub")


def save(browser, name: str) -> None:
    try:
        browser.save_screenshot(str(RESULTS / f"{name}.png"))
    except WebDriverException:
        pass
    try:
        (RESULTS / f"{name}.html").write_text(browser.page_source, encoding="utf-8")
    except Exception:
        pass


def click_text(browser, patterns: tuple[str, ...]) -> bool:
    for pattern in patterns:
        xpath = (
            "//*[self::a or self::button or @role='button']"
            "[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), "
            f"'{pattern.lower()}')]"
        )
        matches = browser.find_elements(By.XPATH, xpath)
        for item in matches:
            if item.is_displayed() and item.is_enabled():
                try:
                    item.click()
                    return True
                except WebDriverException:
                    continue
    return False


def fields(browser):
    return [e for e in browser.find_elements(By.CSS_SELECTOR, "input, textarea") if e.is_displayed()]


def descriptor(browser, element) -> str:
    bits = [
        element.get_attribute("name") or "",
        element.get_attribute("id") or "",
        element.get_attribute("placeholder") or "",
        element.get_attribute("aria-label") or "",
    ]
    eid = element.get_attribute("id")
    if eid:
        try:
            bits.append(browser.find_element(By.CSS_SELECTOR, f"label[for='{eid}']").text)
        except NoSuchElementException:
            pass
    return " ".join(bits).lower()


def fill_matching(browser, needles: tuple[str, ...], value: str) -> bool:
    for element in fields(browser):
        desc = descriptor(browser, element)
        if any(needle in desc for needle in needles):
            element.clear()
            element.send_keys(value)
            return True
    return False


def select_matching(browser, element_id: str, value: str) -> bool:
    try:
        Select(browser.find_element(By.ID, element_id)).select_by_value(value)
        return True
    except NoSuchElementException:
        return False


def login_nextcloud_if_needed(browser) -> bool:
    try:
        user = browser.find_element(By.ID, "user")
        password = browser.find_element(By.ID, "password")
    except NoSuchElementException:
        return False
    user.clear(); user.send_keys(USER)
    password.clear(); password.send_keys(PASSWORD)
    browser.find_element(By.CSS_SELECTOR, "button[type='submit']").click()
    return True


def main() -> int:
    # Host-side readiness. The browser uses the oauch.io Docker network alias.
    for _ in range(60):
        try:
            if httpx.get(PUBLIC_HEALTH_URL, verify=False, timeout=2).status_code < 500:
                break
        except Exception:
            pass
        time.sleep(1)
    else:
        raise RuntimeError("self-hosted OAuch did not become ready")

    with httpx.Client(verify=CA, timeout=20) as client:
        metadata = client.get(DISCOVERY).json()
    (RESULTS / "provider-metadata.json").write_text(json.dumps(metadata, indent=2), encoding="utf-8")

    options = webdriver.ChromeOptions()
    options.add_argument("--headless=new")
    options.add_argument("--no-sandbox")
    options.add_argument("--disable-dev-shm-usage")
    options.set_capability("acceptInsecureCerts", True)
    browser = webdriver.Remote(command_executor=SELENIUM, options=options)
    browser.set_page_load_timeout(30)

    try:
        browser.get(OAUCH_URL)
        save(browser, "01-home")
        click_text(browser, ("continue without signing in", "continue", "anonymous"))
        time.sleep(1)
        # Anonymous mode currently presents a one-time login-link creation page.
        click_text(browser, ("create link", "create login link"))
        time.sleep(2)

        # The current OAuch UI creates a site before showing its endpoint and
        # client settings. Those fields are not present on the initial page.
        click_text(browser, ("new site", "add site", "new profile", "add profile", "new test", "start testing"))
        time.sleep(1)
        save(browser, "02-profile")

        if not fill_matching(browser, ("site name", "name"), "Nextcloud OIDC CI"):
            raise RuntimeError("could not identify the OAuch site name field")
        fill_matching(browser, ("metadata url", "metadata"), DISCOVERY)
        select_matching(browser, "SelectedInitialDocuments", "OIDC")

        submit = browser.find_elements(By.CSS_SELECTOR, "form button[type='submit'],form input[type='submit']")
        if not submit:
            raise RuntimeError("could not submit the OAuch site form")
        submit[0].click()
        WebDriverWait(browser, 15).until(
            lambda current: current.find_elements(By.ID, "Settings_AuthorizationUri")
            or current.find_elements(By.ID, "AuthorizationUri")
        )
        save(browser, "03-settings")

        values = {
            ("site name", "profile name", "name"): "Nextcloud OIDC CI",
            ("authorization endpoint", "authorization url", "authorize url"): metadata["authorization_endpoint"],
            ("token endpoint", "token url"): metadata["token_endpoint"],
            ("client id", "client identifier"): CLIENT_ID,
            ("client secret",): CLIENT_SECRET,
        }
        filled = 0
        for needles, value in values.items():
            filled += int(fill_matching(browser, needles, value))
        if filled < 4:
            save(browser, "error-profile-fields")
            raise RuntimeError(f"could not identify enough OAuch profile fields (filled {filled})")

        # Some releases have an explicit OIDC toggle. Select it if available,
        # but OAuth tests do not depend on it being present.
        for box in browser.find_elements(By.CSS_SELECTOR, "input[type='checkbox'],input[type='radio']"):
            desc = descriptor(browser, box)
            if "openid" in desc or "oidc" in desc:
                if not box.is_selected():
                    try: box.click()
                    except WebDriverException: pass

        if not click_text(browser, ("save", "create", "continue", "start")):
            submit = browser.find_elements(By.CSS_SELECTOR, "button[type='submit'],input[type='submit']")
            if submit:
                submit[0].click()
        time.sleep(2)
        click_text(browser, ("run tests", "start test", "start", "test now"))

        deadline = time.time() + int(os.environ.get("OAUCH_RUN_TIMEOUT", "1500"))
        last_url = ""
        while time.time() < deadline:
            handles = list(browser.window_handles)
            complete = False
            for handle in handles:
                browser.switch_to.window(handle)
                current = browser.current_url
                if current != last_url:
                    last_url = current
                    print(f"OAuch browser: {current}", flush=True)

                if "nextcloud-proxy" in current:
                    login_nextcloud_if_needed(browser)
                    click_text(browser, ("allow", "authorize", "grant", "continue", "yes"))
                    continue

                if "oauch.io" in current:
                    body = browser.find_element(By.TAG_NAME, "body").text.lower()
                    if any(term in body for term in ("test run completed", "testing completed", "final report", "unmitigated threats")):
                        save(browser, "99-complete")
                        (RESULTS / "summary.txt").write_text(browser.find_element(By.TAG_NAME, "body").text, encoding="utf-8")
                        complete = True
                        break

                    # OAuch explicitly needs the user to signal tests that
                    # intentionally fail without a callback.
                    click_text(browser, ("stalled", "no callback", "continue test", "skip", "next test"))

            if complete:
                return 0
            time.sleep(1)

        save(browser, "error-timeout")
        raise RuntimeError("OAuch run did not reach a final report before timeout")
    except Exception:
        save(browser, "error-final")
        raise
    finally:
        browser.quit()


if __name__ == "__main__":
    raise SystemExit(main())
