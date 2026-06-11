#!/usr/bin/env python3
"""Exit non-zero only when exported conformance results contain blocking failures."""

from __future__ import annotations

import argparse
import json
import pathlib
import sys
import zipfile
from collections import Counter


BLOCKING_RESULTS = {"FAILED", "FAILURE", "ERROR", "INTERRUPTED", "UNKNOWN"}
NON_BLOCKING_RESULTS = {"PASSED", "SUCCESS", "SKIPPED", "WARNING", "REVIEW"}
FINISHED_STATUSES = {"FINISHED"}


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
        return str(result).upper()

    status = test_info.get("status")
    if status and status != "FINISHED":
        return str(status).upper()

    return "UNKNOWN"


def is_blocking(test_info: dict) -> bool:
    result = normalized_result(test_info)
    status = str(test_info.get("status") or "").upper()

    if result in BLOCKING_RESULTS:
        return True

    if result not in NON_BLOCKING_RESULTS:
        return True

    return bool(status and status not in FINISHED_STATUSES and result != "SKIPPED")


def test_label(test_info: dict, log_name: str) -> str:
    name = test_info.get("testName") or pathlib.Path(log_name).stem
    test_id = test_info.get("testId") or test_info.get("_id")
    if test_id:
        return f"{name} ({test_id})"
    return str(name)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--results-dir", default="conformance-results", type=pathlib.Path)
    args = parser.parse_args()

    tests = []
    blocking = []
    counts = Counter()

    for export_name, log_name, data in iter_export_logs(args.results_dir):
        test_info = data.get("testInfo", {})
        result = normalized_result(test_info)
        counts[result] += 1
        item = {
            "export": export_name,
            "label": test_label(test_info, log_name),
            "result": result,
            "status": test_info.get("status") or "",
        }
        tests.append(item)
        if is_blocking(test_info):
            blocking.append(item)

    if not tests:
        print(f"No conformance test logs found in {args.results_dir}", file=sys.stderr)
        return 1

    summary = ", ".join(f"{result}={count}" for result, count in sorted(counts.items()))
    print(f"Conformance result summary: {summary}")

    if not blocking:
        print("No blocking conformance failures found.")
        return 0

    print("Blocking conformance failures found:", file=sys.stderr)
    for item in blocking:
        print(
            f"- {item['label']}: result={item['result']} status={item['status']} export={item['export']}",
            file=sys.stderr,
        )
    return 1


if __name__ == "__main__":
    sys.exit(main())
