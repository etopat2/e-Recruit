"""Synthetic TLS SMTP sink used only by the isolated Docker smoke test."""

import base64
import json
from pathlib import Path
import socketserver
import ssl
import subprocess
import threading

subprocess.run([
    "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
    "-keyout", "/tmp/sink.key", "-out", "/tmp/sink.crt", "-subj", "/CN=smtp-sink",
], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.minimum_version = ssl.TLSVersion.TLSv1_2
context.load_cert_chain("/tmp/sink.crt", "/tmp/sink.key")
lock = threading.Lock()


class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        encrypted = False
        self.wfile.write(b"220 smtp-sink ESMTP\r\n")
        while raw := self.rfile.readline():
            command = raw.decode("ascii", errors="replace").strip().upper()
            if command.startswith(("EHLO", "HELO")):
                self.wfile.write(b"250-smtp-sink\r\n250-STARTTLS\r\n250 SIZE 1048576\r\n")
            elif command == "STARTTLS":
                self.wfile.write(b"220 Start TLS\r\n")
                self.connection = context.wrap_socket(self.connection, server_side=True)
                self.rfile = self.connection.makefile("rb")
                self.wfile = self.connection.makefile("wb", buffering=0)
                encrypted = True
            elif command == "DATA":
                self.wfile.write(b"354 End with a dot\r\n")
                lines = []
                while (line := self.rfile.readline()) not in (b".\r\n", b""):
                    lines.append(line[1:] if line.startswith(b"..") else line)
                message = {"tls": encrypted, "raw": base64.b64encode(b"".join(lines)).decode()}
                with lock, Path("/tmp/messages.jsonl").open("a") as output:
                    output.write(json.dumps(message) + "\n")
                self.wfile.write(b"250 2.0.0 synthetic delivery accepted\r\n")
            elif command == "QUIT":
                self.wfile.write(b"221 Bye\r\n")
                return
            else:
                self.wfile.write(b"250 OK\r\n")


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


with Server(("0.0.0.0", 2525), Handler) as server:
    server.serve_forever()
