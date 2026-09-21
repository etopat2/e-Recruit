"""Build first: docker build -t ups-erecruit-mail:verification infra/mail.

Run with any Python 3: python infra/mail/tests/smoke.py
All test networks are internal (no Internet egress); no public recipient is used.
Only resources created with this run's random prefix are removed on exit.
"""

import base64
from email import policy
from email.parser import BytesParser
import json
from pathlib import Path
import subprocess
import time
import uuid

IMAGE = "ups-erecruit-mail:verification"
PREFIX = "erecruit-mail-test-" + uuid.uuid4().hex[:10]
NETWORK = PREFIX + "-trusted"
UNTRUSTED = PREFIX + "-untrusted"
SERVER = PREFIX + "-server"
SINK = PREFIX + "-sink"
SENDER = "noreply@erecruit-mail-fixture.org"
containers, networks, volumes = [], [], []


def docker(*args, check=True):
    result = subprocess.run(["docker", *args], text=True, capture_output=True, timeout=120)
    if check and result.returncode:
        raise RuntimeError(f"docker {' '.join(args[:3])}: {result.stderr.strip()} {result.stdout.strip()}")
    return result.stdout.strip()


def wait_for(check, description):
    for _ in range(45):
        if check():
            return
        time.sleep(1)
    raise AssertionError(description)


def smtp_client(network, target, sender=SENDER, expected=250, body=True):
    script = f"""
import smtplib
from email.message import EmailMessage
with smtplib.SMTP({target!r}, 25, timeout=10) as smtp:
    smtp.ehlo('client.erecruit-mail-fixture.org')
    smtp.mail({sender!r})
    code, text = smtp.rcpt('recipient@destination.test')
    assert code == {expected}, (code, text)
    if {body!r} and code == 250:
        message = EmailMessage()
        message['From'] = {sender!r}
        message['To'] = 'recipient@destination.test'
        message['Subject'] = 'Synthetic self-hosted mail verification'
        message.set_content('Synthetic test only; no credentials or personal information.')
        code, text = smtp.data(message.as_bytes())
        assert code == 250, (code, text)
print('SMTP assertion passed')
"""
    return docker("run", "--rm", "--network", network, "--entrypoint", "python3", IMAGE, "-c", script)


def wait_for_sink():
    probe = "import socket; socket.create_connection(('127.0.0.1', 2525), timeout=1).close(); print('ready')"
    wait_for(lambda: docker("exec", SINK, "python3", "-c", probe, check=False) == "ready", "SMTP sink did not become ready")


try:
    for name in (NETWORK, UNTRUSTED):
        docker("network", "create", "--internal", name)
        networks.append(name)
    trusted = json.loads(docker("network", "inspect", NETWORK))[0]["IPAM"]["Config"][0]["Subnet"]
    # Docker defaults are often /16. Trust only the assigned test client's subnet;
    # create the network explicitly as /24 if Docker's pool used a wider range.
    if int(trusted.split("/")[1]) < 24:
        docker("network", "rm", NETWORK)
        trusted = trusted.split("/")[0] + "/24"
        docker("network", "create", "--internal", "--subnet", trusted, NETWORK)
    for suffix in ("spool", "state", "dkim"):
        name = PREFIX + "-" + suffix
        docker("volume", "create", name)
        volumes.append(name)
    environment = [
        "-e", "MAIL_DOMAIN=erecruit-mail-fixture.org", "-e", "MAIL_HOSTNAME=mail.erecruit-mail-fixture.org",
        "-e", "MAIL_FROM_ADDRESS=" + SENDER, "-e", "MAIL_TRUSTED_SUBNET=" + trusted,
    ]
    docker("run", "-d", "--name", SINK, "--network", NETWORK, "--no-healthcheck", "--entrypoint", "python3",
           "--mount", f"type=bind,src={Path(__file__).with_name('smtp_sink.py').resolve()},dst=/smtp_sink.py,readonly",
           IMAGE, "/smtp_sink.py")
    containers.append(SINK)
    wait_for_sink()
    docker("run", "-d", "--name", SERVER, "--network", NETWORK, *environment,
           "-v", volumes[0] + ":/var/spool/postfix", "-v", volumes[1] + ":/var/lib/postfix",
           "-v", volumes[2] + ":/var/lib/erecruit-mail", IMAGE)
    containers.append(SERVER)
    docker("network", "connect", UNTRUSTED, SERVER)
    wait_for(lambda: "healthy" == json.loads(docker("inspect", SERVER))[0]["State"]["Health"]["Status"], "Mail failed to become healthy")
    assert docker("exec", SERVER, "postconf", "-h", "relayhost") == ""
    assert docker("exec", SERVER, "postconf", "-h", "smtp_tls_security_level") == "encrypt"
    # Only this disposable fixture overrides the recipient route. Production
    # leaves relayhost/transport_maps empty and discovers each recipient's MX.
    docker("exec", SERVER, "postconf", "-e", f"transport_maps=static:smtp:[{SINK}]:2525")
    docker("exec", SERVER, "postfix", "reload")
    trusted_ip = json.loads(docker("inspect", SERVER))[0]["NetworkSettings"]["Networks"][NETWORK]["IPAddress"]
    untrusted_ip = json.loads(docker("inspect", SERVER))[0]["NetworkSettings"]["Networks"][UNTRUSTED]["IPAddress"]
    smtp_client(UNTRUSTED, untrusted_ip, expected=554, body=False)
    print("PASS: untrusted client cannot relay", flush=True)
    smtp_client(NETWORK, trusted_ip, sender="unapproved@erecruit-mail-fixture.org", expected=554, body=False)
    print("PASS: unapproved envelope sender rejected", flush=True)
    smtp_client(NETWORK, trusted_ip)
    read_messages = "from pathlib import Path; p=Path('/tmp/messages.jsonl'); print(p.read_text() if p.exists() else '')"
    wait_for(lambda: bool(docker("exec", SINK, "python3", "-c", read_messages)), "Signed mail did not reach synthetic TLS sink")
    record = json.loads(docker("exec", SINK, "python3", "-c", read_messages).splitlines()[0])
    message = BytesParser(policy=policy.default).parsebytes(base64.b64decode(record["raw"]))
    assert record["tls"] is True
    assert "d=erecruit-mail-fixture.org" in str(message["DKIM-Signature"])
    assert "s=erecruit" in str(message["DKIM-Signature"])
    assert "Synthetic test only" in message.get_body().get_content()
    print("PASS: SMTP accepted, DKIM signed, and delivered through TLS to isolated sink", flush=True)
    docker("stop", SINK)
    smtp_client(NETWORK, trusted_ip)
    wait_for(lambda: "recipient@destination.test" in docker("exec", SERVER, "postqueue", "-p"), "Unavailable destination was not queued")
    public_key_command = ["exec", SERVER, "sha256sum", "/var/lib/erecruit-mail/dkim/erecruit-mail-fixture.org/erecruit/erecruit.txt"]
    public_key_before = docker(*public_key_command)
    docker("restart", SERVER)
    wait_for(lambda: "healthy" == json.loads(docker("inspect", SERVER))[0]["State"]["Health"]["Status"], "Restart failed")
    assert docker(*public_key_command) == public_key_before
    assert "recipient@destination.test" in docker("exec", SERVER, "postqueue", "-p")
    docker("start", SINK)
    wait_for_sink()
    docker("exec", SERVER, "postqueue", "-f")
    wait_for(lambda: len(docker("exec", SINK, "python3", "-c", read_messages).splitlines()) >= 2, "Deferred mail did not deliver after destination recovery")
    print("PASS: DKIM key and queued mail persist across restart; deferred mail retries successfully", flush=True)
    rejected = subprocess.run(["docker", "run", "--rm", "--network", "none", "-e", "MAIL_DOMAIN=example.org", IMAGE, "check-config"], text=True, capture_output=True, timeout=30)
    assert rejected.returncode != 0 and "placeholder" in rejected.stderr
    print("PASS: placeholder deployment rejected", flush=True)
    print("Self-hosted mail smoke tests passed. Public inbox delivery was not attempted.", flush=True)
except Exception:
    if SERVER in containers:
        print(docker("logs", "--tail", "40", SERVER, check=False), flush=True)
        print(docker("inspect", "--format", "{{json .State}}", SERVER, check=False), flush=True)
        print(docker("top", SERVER, check=False), flush=True)
    raise
finally:
    for name in reversed(containers):
        docker("rm", "-f", name, check=False)
    for name in reversed(networks):
        docker("network", "rm", name, check=False)
    for name in reversed(volumes):
        docker("volume", "rm", name, check=False)
