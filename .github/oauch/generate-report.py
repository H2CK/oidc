#!/usr/bin/env python3
"""Create a Markdown report from an OAuch result page."""

from __future__ import annotations

import argparse
import re
from html.parser import HTMLParser
from pathlib import Path


def compact(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def table_cell(value: str) -> str:
    return compact(value).replace("|", "\\|")


class ResultsParser(HTMLParser):
    """Extract the overview and failed-test tab without an HTML dependency."""

    def __init__(self) -> None:
        super().__init__()
        self.page_text: list[str] = []
        self.section: str | None = None
        self.section_depth = 0
        self.current_test: list[str] | None = None
        self.current_test_id = ""
        self.failed_tests: list[tuple[str, str]] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if tag == "div":
            if self.section is not None:
                self.section_depth += 1
            elif attributes.get("id") == "custom-tabs-three-failed":
                self.section = "failed"
                self.section_depth = 1
        if self.section == "failed" and tag == "li":
            self.current_test = []
            self.current_test_id = ""
        if self.current_test is not None and tag == "a":
            match = re.search(r"/Tests/Info/([^?/#]+)", attributes.get("href") or "")
            if match:
                self.current_test_id = match.group(1)

    def handle_endtag(self, tag: str) -> None:
        if self.section == "failed" and tag == "li" and self.current_test is not None:
            self.failed_tests.append((compact(" ".join(self.current_test)), self.current_test_id))
            self.current_test = None
        if self.section is not None and tag == "div":
            self.section_depth -= 1
            if self.section_depth == 0:
                self.section = None

    def handle_data(self, data: str) -> None:
        self.page_text.append(data)
        if self.current_test is not None:
            self.current_test.append(data)


def value(text: str, label: str) -> str:
    match = re.search(rf"{re.escape(label)}:\s*([^\n]+?)(?=(?:\s+[A-Z][^:]+:|$))", text)
    return compact(match.group(1)) if match else "not available"


def failed_test(text: str, test_id: str) -> tuple[str, str, str]:
    match = re.match(r"(.+?):\s*(YES|NO|\?\?)\s*\[\s*(mandatory|recommended|optional)", text, re.I)
    if not match:
        return text, "unknown", test_id
    return match.group(1), match.group(3).lower(), test_id


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()

    if not args.input.is_file():
        args.output.write_text(
            "# OAuch OAuth Security Conformance Report\n\n"
            "No OAuch result page was produced; inspect `runner.log` and the diagnostic artifacts.\n",
            encoding="utf-8",
        )
        return 0

    result_parser = ResultsParser()
    result_parser.feed(args.input.read_text(encoding="utf-8"))
    text = compact(" ".join(result_parser.page_text))
    incomplete = "pending test(s)" in text
    lines = ["# OAuch OAuth Security Conformance Report", ""]
    if incomplete:
        lines.extend([
            "> [!WARNING]",
            "> OAuch reports pending tests. The results below are incomplete.",
            "",
        ])
    lines.extend([
        "## Summary",
        "",
        f"- Mitigated threats: {value(text, 'Mitigated threats')}",
        f"- Partially mitigated threats: {value(text, 'Partially mitigated threats')}",
        f"- Unmitigated threats: {value(text, 'Unmitigated threats')}",
        f"- Mandatory test cases failed: {value(text, 'Mandatory test cases failed')}",
        f"- Recommended test cases failed: {value(text, 'Recommended test cases failed')}",
        f"- Optional test cases failed: {value(text, 'Optional test cases failed')}",
        f"- Overall test cases failed: {value(text, 'Overall test cases failed')}",
        "",
        "## Failed Tests",
        "",
        "| Requirement | Test | OAuch test ID |",
        "| --- | --- | --- |",
    ])
    if result_parser.failed_tests:
        for failed, test_id in result_parser.failed_tests:
            name, requirement, test_id = failed_test(failed, test_id)
            lines.append(f"| {table_cell(requirement)} | {table_cell(name)} | `{test_id}` |")
    else:
        lines.append("| – | No failed tests reported | – |")
    lines.extend(["", "## Source", "", f"- OAuch result page: `{args.input.name}`", ""])
    args.output.write_text("\n".join(lines), encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
