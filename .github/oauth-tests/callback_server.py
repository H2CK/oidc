#!/usr/bin/env python3
"""Tiny HTTPS redirect sink used by the browser-driven authorization flow."""

from __future__ import annotations

import json
import os
import ssl
import tempfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlsplit

RESULTS = Path(os.environ.get("CALLBACK_RESULTS", "/results"))
RESULTS.mkdir(parents=True, exist_ok=True)
CALLBACK_FILE = RESULTS / "callback.json"


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        parsed = urlsplit(self.path)
        if parsed.path not in {"/callback", "/other"}:
            self.send_response(404)
            self.end_headers()
            return

        query = {key: values[-1] for key, values in parse_qs(parsed.query).items()}
        payload = {"path": parsed.path, "query": query}
        fd, tmp_name = tempfile.mkstemp(dir=RESULTS, prefix="callback-", suffix=".json")
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(payload, handle)
        os.chmod(tmp_name, 0o644)
        os.replace(tmp_name, CALLBACK_FILE)

        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.end_headers()
        self.wfile.write(b"<html><body>OAuth callback received.</body></html>")

    def log_message(self, fmt: str, *args: object) -> None:
        print(fmt % args, flush=True)


server = ThreadingHTTPServer(("0.0.0.0", 9444), Handler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain("/certs/server.crt", "/certs/server.key")
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
