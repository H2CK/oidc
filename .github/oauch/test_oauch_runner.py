"""Regression checks for OAuch's rapidly changing browser pages."""

from __future__ import annotations

import importlib.util
import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from selenium.common.exceptions import StaleElementReferenceException
from selenium.webdriver.common.by import By


class LoginBrowser:
    def __init__(self, stale_count: int, next_url: str | None = None):
        self.current_url = "https://nextcloud-proxy:8443/index.php/login"
        self.stale_count = stale_count
        self.next_url = next_url
        self.submitted = 0

    def find_element(self, by: str, value: str):
        if (by, value) not in (
            (By.ID, "user"),
            (By.ID, "password"),
            (By.CSS_SELECTOR, "button[type='submit']"),
        ):
            raise AssertionError("unexpected element lookup")
        return LoginElement(self, value)


class LoginElement:
    def __init__(self, browser: LoginBrowser, name: str):
        self.browser = browser
        self.name = name

    def is_displayed(self) -> bool:
        return True

    def clear(self) -> None:
        if self.name == "user" and self.browser.stale_count:
            self.browser.stale_count -= 1
            if self.browser.next_url:
                self.browser.current_url = self.browser.next_url
            raise StaleElementReferenceException("login form was replaced")

    def send_keys(self, value: str) -> None:
        assert value

    def click(self) -> None:
        self.browser.submitted += 1


class OAuchRunnerLoginTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        with TemporaryDirectory() as results:
            with patch.dict(os.environ, {
                "OAUCH_CLIENT_ID": "test-client",
                "OAUCH_CLIENT_SECRET": "test-secret",
                "OAUCH_RESULTS_DIR": results,
            }):
                path = Path(__file__).with_name("oauch-runner.py")
                spec = importlib.util.spec_from_file_location("oauch_runner", path)
                assert spec is not None and spec.loader is not None
                cls.runner = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(cls.runner)

    def test_form_replaced_once_is_retried(self) -> None:
        browser = LoginBrowser(stale_count=1)
        with patch.object(self.runner.time, "sleep"):
            self.assertTrue(self.runner.login_nextcloud_if_needed(browser))
        self.assertEqual(browser.submitted, 1)

    def test_navigation_to_callback_during_login_is_not_a_failure(self) -> None:
        browser = LoginBrowser(
            stale_count=1,
            next_url="https://oauch.io/Callback?code=dummy-code&state=dummy-state",
        )
        with patch.object(self.runner.time, "sleep"):
            self.assertFalse(self.runner.login_nextcloud_if_needed(browser))
        self.assertEqual(browser.submitted, 0)

    def test_login_form_that_keeps_changing_reports_an_error(self) -> None:
        browser = LoginBrowser(stale_count=7)
        with patch.object(self.runner.time, "sleep"):
            with self.assertRaisesRegex(RuntimeError, "kept changing"):
                self.runner.login_nextcloud_if_needed(browser)

    def test_logged_locations_omit_query_parameters_and_run_ids(self) -> None:
        location = self.runner.safe_browser_location(
            "https://oauch.io/Dashboard/Running/dummy-run-id?state=dummy-state"
        )
        self.assertEqual(location, "oauch.io /Dashboard/Running")


if __name__ == "__main__":
    unittest.main()
