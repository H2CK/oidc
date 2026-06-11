#!/usr/bin/env python3
"""
Drive OIDC conformance front-channel URLs with Selenium/Chromium.

The OpenID conformance suite's built-in BrowserControl uses HtmlUnit. For
Nextcloud, the login form is rendered by modern JavaScript, so the suite is
configured without browser commands and this worker handles exposed browser URLs
through the suite's existing runner API.
"""

import base64
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
LOGIN_REDIRECT_TIMEOUT_SECONDS = int(os.environ.get("CONFORMANCE_BROWSER_LOGIN_REDIRECT_TIMEOUT", "15"))
PLACEHOLDER_CHECK_SECONDS = float(os.environ.get("CONFORMANCE_BROWSER_PLACEHOLDER_CHECK_SECONDS", "2"))
SCREENSHOT_STABILITY_SECONDS = float(os.environ.get("CONFORMANCE_BROWSER_SCREENSHOT_STABILITY_SECONDS", "2"))


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
    login_url = driver.current_url
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
    wait_for_login_redirect(driver, login_url)


def wait_for_login_redirect(driver, login_url):
    deadline = time.monotonic() + LOGIN_REDIRECT_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        current_url = driver.current_url
        if current_url != login_url:
            return
        if not is_login_page(driver):
            return
        time.sleep(0.2)


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


def page_diagnostics(driver):
    try:
        ready_state = driver.execute_script("return document.readyState")
    except Exception:
        ready_state = "unavailable"
    try:
        body = driver.execute_script("return document.body ? document.body.innerText : ''")
    except Exception:
        body = ""
    body = " ".join((body or "").split())
    if len(body) > 1000:
        body = body[:1000] + "..."
    return {
        "url": driver.current_url,
        "title": driver.title,
        "ready_state": ready_state,
        "body": body,
    }


def page_ready_for_screenshot(driver):
    diag = page_diagnostics(driver)
    if diag["ready_state"] != "complete":
        return False
    return bool(diag["title"] or diag["body"])


def get_pending_upload_placeholder(client, test_id, uploaded_placeholders):
    try:
        response = client.get(f"{CONFORMANCE_SERVER}/api/log/{test_id}")
        response.raise_for_status()
        log_entries = response.json()
    except Exception as exc:
        log(f"Unable to read log placeholders for {test_id}: {exc}")
        return None

    for entry in reversed(log_entries):
        placeholder = entry.get("upload")
        if (
            placeholder
            and entry.get("result") == "REVIEW"
            and (test_id, placeholder) not in uploaded_placeholders
        ):
            return placeholder

    return None


def screenshot_data_urls(driver):
    try:
        for quality in (80, 60, 40):
            result = driver.execute_cdp_cmd(
                "Page.captureScreenshot",
                {
                    "format": "jpeg",
                    "quality": quality,
                    "captureBeyondViewport": False,
                },
            )
            encoded = result.get("data")
            if encoded:
                yield f"jpeg q{quality}", f"data:image/jpeg;base64,{encoded}"
    except Exception as exc:
        log(f"Unable to capture JPEG screenshot through Chrome DevTools: {exc}")

    encoded = base64.b64encode(driver.get_screenshot_as_png()).decode("ascii")
    yield "png", f"data:image/png;base64,{encoded}"


def encoded_data_size(data_url):
    marker = "base64,"
    marker_index = data_url.find(marker)
    if marker_index == -1:
        return 0
    try:
        return len(base64.b64decode(data_url[marker_index + len(marker):]))
    except Exception:
        return 0


def upload_review_screenshot(client, test_id, placeholder, driver):
    url = f"{CONFORMANCE_SERVER}/api/log/{test_id}/images/{placeholder}"

    for label, data_url in screenshot_data_urls(driver):
        size = encoded_data_size(data_url)
        log(f"Uploading {label} screenshot for placeholder {placeholder} ({size} bytes)")
        try:
            response = client.post(
                url,
                content=data_url,
                headers={"Content-Type": "text/plain; charset=utf-8"},
            )
        except Exception as exc:
            log(f"Unable to upload screenshot for {test_id}: {exc}")
            return False

        if response.status_code == 200:
            log(f"Uploaded screenshot for review placeholder {placeholder}")
            return True

        body = " ".join(response.text.split())
        if len(body) > 300:
            body = body[:300] + "..."
        log(f"Screenshot upload failed with HTTP {response.status_code}: {body}")

        if response.status_code != 400:
            return False

    return False


def drive_url(driver, client, test_id, uploaded_placeholders, method, url):
    log(f"Visiting {method} {url}")
    if method.upper() == "POST":
        submit_post(driver, url)
    else:
        driver.get(url)

    deadline = time.monotonic() + VISIT_TIMEOUT_SECONDS
    last_seen_url = None
    last_url_changed_at = time.monotonic()
    next_placeholder_check = 0
    while time.monotonic() < deadline:
        now = time.monotonic()
        current_url = driver.current_url
        if current_url != last_seen_url:
            last_seen_url = current_url
            last_url_changed_at = now
            log(f"Browser at {current_url}")

        if is_conformance_callback(current_url):
            log(f"Reached conformance callback {current_url}")
            return current_url

        if is_login_page(driver):
            login(driver)
            continue

        if grant_consent_if_present(driver):
            continue

        if (
            now >= next_placeholder_check
            and now - last_url_changed_at >= SCREENSHOT_STABILITY_SECONDS
            and page_ready_for_screenshot(driver)
        ):
            next_placeholder_check = now + PLACEHOLDER_CHECK_SECONDS
            placeholder = get_pending_upload_placeholder(client, test_id, uploaded_placeholders)
            if placeholder:
                log(f"Review placeholder {placeholder} is pending at {current_url}")
                if upload_review_screenshot(client, test_id, placeholder, driver):
                    uploaded_placeholders.add((test_id, placeholder))
                    return current_url

        time.sleep(0.5)

    log(f"Timed out waiting for callback; current URL is {driver.current_url}")
    diag = page_diagnostics(driver)
    log(f"Timeout page title: {diag['title']}")
    log(f"Timeout page readyState: {diag['ready_state']}")
    log(f"Timeout page body: {diag['body']}")
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


def get_driver(drivers, test_id):
    driver = drivers.get(test_id)
    if driver is None:
        driver = new_driver()
        drivers[test_id] = driver
    return driver


def close_driver(drivers, test_id):
    driver = drivers.pop(test_id, None)
    if driver is None:
        return
    log(f"Closing browser session for {test_id}")
    try:
        driver.quit()
    except Exception as exc:
        log(f"Unable to close browser session for {test_id}: {exc}")


def main():
    drivers = {}
    processed = set()
    uploaded_placeholders = set()
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
                        for old_test_id in list(drivers):
                            if old_test_id != test_id:
                                close_driver(drivers, old_test_id)

                    active_test_id = test_id
                    driver = get_driver(drivers, test_id)
                    processed.add(key)

                    try:
                        drive_url(driver, client, test_id, uploaded_placeholders, method, url)
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
