"""Regression checks for the browser side of the OIDC conformance workflow."""

import importlib.util
import itertools
import os
from pathlib import Path
import unittest
from unittest.mock import Mock, patch

from selenium.common.exceptions import NoSuchElementException


class Browser:
    def __init__(self, url):
        self.current_url = url


class BrowserRunnerTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        with patch.dict(os.environ, {
            "OIDC_TEST_USER": "test-user",
            "OIDC_TEST_PASSWORD": "test-password",
        }):
            source = Path(__file__).with_name("browser-runner.py")
            spec = importlib.util.spec_from_file_location("conformance_browser_runner", source)
            assert spec is not None and spec.loader is not None
            cls.runner = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(cls.runner)

    def test_login_detection_checks_host_and_path_not_query(self):
        browser = Browser("https://nginx:8443/test/a/callback?redirect_url=/index.php/login")
        self.assertFalse(self.runner.is_login_page(browser))
        browser.current_url = "https://nextcloud-proxy:8443/index.php/login?redirect_url=/apps/oidc/resume"
        self.assertTrue(self.runner.is_login_page(browser))

    def test_changed_login_query_does_not_count_as_redirect(self):
        browser = Browser("https://nextcloud-proxy:8443/index.php/login?step=1")
        sleeps = []

        def advance(_seconds):
            sleeps.append(True)
            browser.current_url = (
                "https://nextcloud-proxy:8443/index.php/login?step=2"
                if len(sleeps) == 1 else "https://nginx:8443/test/a/callback?code=dummy"
            )

        with patch.object(self.runner.time, "monotonic", side_effect=itertools.count()), \
                patch.object(self.runner.time, "sleep", side_effect=advance):
            self.assertTrue(self.runner.wait_for_login_redirect(browser))
        self.assertEqual(len(sleeps), 2)

    def test_submitted_login_that_does_not_redirect_is_not_retried(self):
        browser = Browser("https://nextcloud-proxy:8443/index.php/login")
        field = Mock()
        submit = Mock()
        with patch.object(self.runner, "first_present", return_value=field), \
                patch.object(self.runner, "first_clickable", return_value=submit), \
                patch.object(self.runner, "wait_for_login_redirect", return_value=False):
            with self.assertRaisesRegex(RuntimeError, "remained on the login page"):
                self.runner.login(browser)
        submit.click.assert_called_once_with()

    def test_page_changed_while_locating_button(self):
        browser = Browser("https://nextcloud-proxy:8443/index.php/login")

        def move_to_callback(*_args, **_kwargs):
            browser.current_url = "https://nginx:8443/test/a/callback?code=dummy"
            raise NoSuchElementException("form was replaced")

        with patch.object(self.runner, "first_present", return_value=Mock()), \
                patch.object(self.runner, "first_clickable", side_effect=move_to_callback):
            self.assertFalse(self.runner.login(browser))

    def test_failed_browser_visit_is_never_acknowledged(self):
        client = Mock()
        with patch.object(self.runner, "drive_url", side_effect=RuntimeError("browser failed")):
            with self.assertRaisesRegex(RuntimeError, "browser failed"):
                self.runner.visit_and_acknowledge(Mock(), client, "test-id", set(), "GET", "https://example.test")
        client.post.assert_not_called()

    def test_logged_url_excludes_authorization_parameters(self):
        url = "https://nextcloud-proxy:8443/index.php/apps/oidc/authorize?code=dummy&state=dummy"
        self.assertEqual(self.runner.describe_url(url), "op /index.php/apps/oidc/authorize")


if __name__ == "__main__":
    unittest.main()
