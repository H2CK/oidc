from __future__ import annotations

import json
import os
from pathlib import Path

import pytest

from oauth_testlib import OAuthHarness

_RESULTS: dict[str, dict[str, str]] = {}


def pytest_configure(config: pytest.Config) -> None:
    Path(os.environ.get("OAUTH_RESULTS_DIR", "oauth-results")).mkdir(parents=True, exist_ok=True)


@pytest.fixture(scope="session")
def oauth() -> OAuthHarness:
    harness = OAuthHarness()
    yield harness
    harness.close()


@pytest.hookimpl(hookwrapper=True)
def pytest_runtest_makereport(item: pytest.Item, call: pytest.CallInfo):
    outcome = yield
    report = outcome.get_result()
    if report.when != "call":
        return
    rfc = item.get_closest_marker("rfc")
    optional = item.get_closest_marker("optional_extension")
    advisory = item.get_closest_marker("advisory")
    source = ""
    if rfc:
        number = str(rfc.args[0]) if rfc.args else ""
        section = rfc.kwargs.get("section", "")
        source = f"{number} {section}".strip()
    status = "PASS" if report.passed else "SKIP" if report.skipped else "FAIL"
    _RESULTS[item.nodeid] = {
        "status": status,
        "source": source,
        "extension": str(optional.args[0]) if optional and optional.args else "",
        "advisory": "yes" if advisory else "no",
        "reason": str(getattr(report, "longrepr", ""))[:500] if not report.passed else "",
    }


def pytest_sessionfinish(session: pytest.Session, exitstatus: int) -> None:
    result_dir = Path(os.environ.get("OAUTH_RESULTS_DIR", "oauth-results"))
    result_dir.mkdir(parents=True, exist_ok=True)
    (result_dir / "results.json").write_text(json.dumps(_RESULTS, indent=2), encoding="utf-8")

    lines = [
        "# OAuth conformance test result",
        "",
        "| Test | RFC / source | Extension | Advisory | Result |",
        "|---|---|---|---|---|",
    ]
    for name, data in sorted(_RESULTS.items()):
        lines.append(
            f"| `{name}` | {data['source']} | {data['extension']} | {data['advisory']} | **{data['status']}** |"
        )
    lines += ["", f"Pytest exit status: `{exitstatus}`", ""]
    (result_dir / "OAUTH-CONFORMANCE.md").write_text("\n".join(lines), encoding="utf-8")
