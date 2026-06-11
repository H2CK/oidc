#!/usr/bin/env python3
"""Create a Markdown summary from OpenID conformance-suite exports."""

from __future__ import annotations

import argparse
import datetime as dt
import json
import pathlib
import re
import zipfile
from collections import Counter


ISSUE_RESULTS = {"FAILURE", "ERROR", "WARNING"}
RESULT_ORDER = {
    "FAILED": 0,
    "FAILURE": 0,
    "ERROR": 1,
    "INTERRUPTED": 2,
    "WARNING": 3,
    "PASSED": 4,
    "SUCCESS": 4,
    "SKIPPED": 5,
    "UNKNOWN": 6,
}


def compact(value: object) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip()


def table_cell(value: object) -> str:
    text = compact(value)
    return text.replace("|", "\\|")


def truncate(value: str, max_length: int) -> str:
    if len(value) <= max_length:
        return value
    return value[: max_length - 3].rstrip() + "..."


def iter_export_logs(results_dir: pathlib.Path):
    for zip_path in sorted(results_dir.glob("*.zip")):
        with zipfile.ZipFile(zip_path) as export:
            for name in sorted(export.namelist()):
                if not name.endswith(".json"):
                    continue
                with export.open(name) as handle:
                    yield zip_path.name, name, json.load(handle)

    for json_path in sorted(results_dir.glob("test-log-*.json")):
        with json_path.open(encoding="utf-8") as handle:
            yield json_path.name, json_path.name, json.load(handle)


def normalized_result(test_info: dict) -> str:
    result = test_info.get("result")
    if result:
        return str(result)
    status = test_info.get("status")
    if status and status != "FINISHED":
        return str(status)
    return "UNKNOWN"


def issue_summary(results: list[dict], max_issues: int) -> str:
    issues = [
        f"{item.get('result')}: {compact(item.get('msg'))}"
        for item in results
        if item.get("result") in ISSUE_RESULTS and item.get("msg")
    ]
    if not issues:
        return ""
    extra = len(issues) - max_issues
    selected = issues[:max_issues]
    if extra > 0:
        selected.append(f"+ {extra} more")
    return "; ".join(selected)


def collect_tests(results_dir: pathlib.Path, max_issues: int) -> list[dict]:
    tests = []
    for export_name, log_name, data in iter_export_logs(results_dir):
        test_info = data.get("testInfo", {})
        test_results = data.get("results", [])
        result = normalized_result(test_info)
        tests.append(
            {
                "name": test_info.get("testName", pathlib.Path(log_name).stem),
                "id": test_info.get("testId", test_info.get("_id", "")),
                "status": test_info.get("status", ""),
                "result": result,
                "summary": compact(test_info.get("summary")),
                "issues": issue_summary(test_results, max_issues),
                "started": test_info.get("started", ""),
                "export": export_name,
            }
        )
    return sorted(
        tests,
        key=lambda item: (
            RESULT_ORDER.get(item["result"], RESULT_ORDER["UNKNOWN"]),
            item["name"],
            item["id"],
        ),
    )


def write_report(tests: list[dict], output: pathlib.Path, results_dir: pathlib.Path) -> None:
    now = dt.datetime.now(dt.UTC).replace(microsecond=0).isoformat()
    counts = Counter(test["result"] for test in tests)

    lines = [
        "# OIDC Conformance Report",
        "",
        f"Generated: {now}",
        f"Results source: `{results_dir}`",
        f"Executed tests: {len(tests)}",
        "",
        "## Result Summary",
        "",
        "| Result | Count |",
        "| --- | ---: |",
    ]

    if counts:
        for result, count in sorted(counts.items(), key=lambda item: RESULT_ORDER.get(item[0], RESULT_ORDER["UNKNOWN"])):
            lines.append(f"| {table_cell(result)} | {count} |")
    else:
        lines.append("| UNKNOWN | 0 |")

    lines.extend(
        [
            "",
            "## Executed Tests",
            "",
            "| Result | Status | Test | Description | Issues |",
            "| --- | --- | --- | --- | --- |",
        ]
    )

    if tests:
        for test in tests:
            test_name = test["name"]
            if test["id"]:
                test_name = f"{test_name} ({test['id']})"
            lines.append(
                "| "
                + " | ".join(
                    [
                        table_cell(test["result"]),
                        table_cell(test["status"]),
                        table_cell(test_name),
                        table_cell(truncate(test["summary"], 260)),
                        table_cell(truncate(test["issues"], 320)),
                    ]
                )
                + " |"
            )
    else:
        lines.append("| UNKNOWN | UNKNOWN | No conformance test logs found |  |  |")

    lines.extend(
        [
            "",
            "## Notes",
            "",
            "- `WARNING` is reported by the conformance suite for behavior that may still complete the test module.",
            "- `INTERRUPTED` means the test module did not reach a final pass/fail result in the exported run.",
            "- Full logs and signed test exports are available in the workflow artifact.",
            "",
        ]
    )

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text("\n".join(lines), encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--results-dir", default="conformance-results", type=pathlib.Path)
    parser.add_argument("--output", default="CONFORMANCE.md", type=pathlib.Path)
    parser.add_argument("--max-issues", default=3, type=int)
    args = parser.parse_args()

    tests = collect_tests(args.results_dir, args.max_issues)
    write_report(tests, args.output, args.results_dir)


if __name__ == "__main__":
    main()
