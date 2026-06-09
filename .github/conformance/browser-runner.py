#!/usr/bin/env python3
"""
Drive OIDC conformance front-channel URLs with Selenium/Chromium.

The OpenID conformance suite's built-in BrowserControl uses HtmlUnit. For
Nextcloud, the login form is rendered by modern JavaScript, so the suite is
configured without browser commands and this worker handles exposed browser URLs
through the suite's existing runner API.
"""

import os
import sys
import time
import urllib.parse

import httpx
from selenium import webdriver
from selenium.common.exceptions import NoSuchElementException
from selenium.common.exceptions import StaleElementReferenceException
from selenium.webdriver import ChromeOptions
from selenium.webdriver.common.by import By


CONFORMANCE_SERVER = os.environ.get("CONFORMANCE_SERVER", "https://nginx:8443/").rstrip("/")
SELENIUM_REMOTE_URL = os.environ.get("SELENIUM_REMOTE_URL") or os.environ.get("SELENIUM_HUB_HOST") or "http://chrome:4444/wd/hub"
OIDC_TEST_USER = os.environ["OIDC_TEST_USER"]
OIDC_TEST_PASSWORD = os.environ["OIDC_TEST_PASSWORD"]
POLL_SECONDS = float(os.environ.get("CONFORMANCE_BROWSER_POLL_SECONDS", "1"))
VISIT_TIMEOUT_SECONDS = int(os.environ.get("CONFORMANCE_BROWSER_VISIT_TIMEOUT", "90"))


def log(message):
    print(f"[conformance-browser] {message}", flush=True)


def new_driver():
    options = ChromeOptions()
    options.set_capability("acceptInsecureCerts", True)
    for argument in (
        "--headless=new",
        "--no-sandbox",
        "--disable-dev-shm-usage",
        "--ignore-certificate-errors",
        "--window-size=1280,1000",
    ):
        options.add_argument(argument)
    return webdriver.Remote(command_executor=SELENIUM_REMOTE_URL, options=options)


def first_present(driver, selectors, timeout=5):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        for by, value in selectors:
            try:
                element = driver.find_element(by, value)
                if element.is_displayed():
                    return element
            except (NoSuchElementException, StaleElementReferenceException):
                pass
        time.sleep(0.2)
    raise NoSuchElementException(f"None of the selectors were found: {selectors}")


def first_clickable(driver, selectors, timeout=5):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        for by, value in selectors:
            try:
                element = driver.find_element(by, value)
                if element.is_displayed() and element.is_enabled():
                    return element
            except (NoSuchElementException, StaleElementReferenceException):
                pass
        time.sleep(0.2)
    raise NoSuchElementException(f"None of the selectors were clickable: {selectors}")


def submit_post(driver, url):
    parsed = urllib.parse.urlsplit(url)
    action = urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
    params = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)

    inputs = "\n".join(
        f'<input type="hidden" name="{html_escape(name)}" value="{html_escape(value)}">'
        for name, value in params
    )
    page = f"""
<!doctype html>
<html>
  <body>
    <form id="post-form" method="post" action="{html_escape(action)}">
      {inputs}
    </form>
    <script>document.getElementById('post-form').submit();</script>
  </body>
</html>
"""
    driver.get("data:text/html;charset=utf-8," + urllib.parse.quote(page))


def html_escape(value):
    return (
        value.replace("&", "&amp;")
        .replace('"', "&quot;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
    )


def is_login_page(driver):
    current_url = driver.current_url
    if "/index.php/login" in current_url:
        return True
    try:
        return bool(driver.find_elements(By.ID, "login"))
    except StaleElementReferenceException:
        return False


def login(driver):
    log(f"Logging in at {driver.current_url}")
    user = first_present(driver, ((By.ID, "user"), (By.NAME, "user")), timeout=30)
    password = first_present(driver, ((By.ID, "password"), (By.NAME, "password")), timeout=30)
    user.clear()
    user.send_keys(OIDC_TEST_USER)
    password.clear()
    password.send_keys(OIDC_TEST_PASSWORD)
    submit = first_clickable(
        driver,
        (
            (By.ID, "submit-form"),
            (By.CSS_SELECTOR, "button[type='submit']"),
            (By.CSS_SELECTOR, "input[type='submit']"),
        ),
        timeout=10,
    )
    submit.click()


def grant_consent_if_present(driver):
    if "/apps/oidc/consent" not in driver.current_url and not driver.find_elements(By.ID, "oidc-consent"):
        return False

    try:
        allow = first_clickable(
            driver,
            (
                (By.XPATH, "//button[normalize-space()='Allow']"),
                (By.XPATH, "//button[contains(normalize-space(.), 'Allow')]"),
            ),
            timeout=15,
        )
        log("Granting consent")
        allow.click()
        return True
    except NoSuchElementException:
        return False


def is_conformance_callback(url):
    parsed = urllib.parse.urlsplit(url)
    return parsed.netloc == "nginx:8443" and (
        parsed.path.startswith("/test/a/")
        or parsed.path.startswith("/test-mtls/a/")
    )


def drive_url(driver, method, url):
    log(f"Visiting {method} {url}")
    if method.upper() == "POST":
        submit_post(driver, url)
    else:
        driver.get(url)

    deadline = time.monotonic() + VISIT_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        current_url = driver.current_url
        if is_conformance_callback(current_url):
            log(f"Reached conformance callback {current_url}")
            return current_url

        if is_login_page(driver):
            login(driver)
            continue

        if grant_consent_if_present(driver):
            continue

        time.sleep(0.5)

    log(f"Timed out waiting for callback; current URL is {driver.current_url}")
    return driver.current_url


def get_browser_items(status):
    browser = status.get("browser") or {}
    items = browser.get("urlsWithMethod") or []
    if items:
        return [
            {"url": item["url"], "method": item.get("method") or "GET"}
            for item in items
            if item.get("url")
        ]
    return [{"url": url, "method": "GET"} for url in browser.get("urls", [])]


def main():
    drivers = {}
    processed = set()
    active_test_id = None

    with httpx.Client(verify=False, timeout=15.0) as client:
        while True:
            try:
                running = client.get(f"{CONFORMANCE_SERVER}/api/runner/running").json()
            except Exception as exc:
                log(f"Unable to read running tests: {exc}")
                time.sleep(POLL_SECONDS)
                continue

            for test_id in running:
                try:
                    status = client.get(f"{CONFORMANCE_SERVER}/api/runner/{test_id}").json()
                except Exception as exc:
                    log(f"Unable to read status for {test_id}: {exc}")
                    continue

                for item in get_browser_items(status):
                    url = item["url"]
                    method = item["method"].upper()
                    key = (test_id, method, url)
                    if key in processed:
                        continue

                    if active_test_id is not None and active_test_id != test_id:
                        for old_test_id, old_driver in list(drivers.items()):
                            if old_test_id != test_id:
                                log(f"Closing browser session for {old_test_id}")
                                old_driver.quit()
                                drivers.pop(old_test_id, None)

                    active_test_id = test_id
                    driver = drivers.setdefault(test_id, new_driver())
                    processed.add(key)

                    try:
                        drive_url(driver, method, url)
                    except Exception as exc:
                        log(f"Browser visit failed for {test_id}: {exc}")
                    finally:
                        try:
                            client.post(
                                f"{CONFORMANCE_SERVER}/api/runner/browser/{test_id}/visit",
                                params={"url": url},
                            )
                        except Exception as exc:
                            log(f"Unable to mark URL visited for {test_id}: {exc}")

            time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
