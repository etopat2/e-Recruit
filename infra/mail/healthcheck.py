"""Process/SMTP readiness only; this does not claim inbox delivery."""

import smtplib
import socket
import subprocess

subprocess.run(["postfix", "status"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
with socket.create_connection(("127.0.0.1", 8891), timeout=2):
    pass
with smtplib.SMTP("127.0.0.1", 25, timeout=2) as smtp:
    code, _ = smtp.noop()
    if code != 250:
        raise SystemExit(1)
