#!/usr/bin/env python3

import os
import string
import sys


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: write-config.py TEMPLATE OUTPUT", file=sys.stderr)
        return 2

    with open(sys.argv[1], encoding="utf-8") as template_file:
        template = string.Template(template_file.read())

    rendered = template.safe_substitute(os.environ)

    with open(sys.argv[2], "w", encoding="utf-8") as output_file:
        output_file.write(rendered)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
