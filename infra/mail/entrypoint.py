"""Configure a private, DKIM-signed, direct-to-MX Postfix service."""

import ipaddress
import os
from pathlib import Path
import pwd
import re
import signal
import socket
import subprocess
import sys
import time


def require_domain(name: str) -> str:
    value = os.environ.get(name, "").strip().lower()
    labels = value.split(".")
    if (
        len(labels) < 2
        or len(value) > 253
        or any(not re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?", label) for label in labels)
        or labels[-1] in {"test", "invalid", "localhost", "local", "example"}
        or any(value == reserved or value.endswith("." + reserved) for reserved in ("example.com", "example.net", "example.org"))
    ):
        raise ValueError(f"{name} must be a real, owned DNS domain, not a placeholder")
    return value


def configuration() -> dict:
    domain = require_domain("MAIL_DOMAIN")
    hostname = require_domain("MAIL_HOSTNAME")
    if not hostname.endswith("." + domain):
        raise ValueError("MAIL_HOSTNAME must be a host below MAIL_DOMAIN")
    sender = os.environ.get("MAIL_FROM_ADDRESS", "").strip().lower()
    if not re.fullmatch(r"[a-z0-9.!#$%&'*+=?^_`{|}~-]+@" + re.escape(domain), sender):
        raise ValueError("MAIL_FROM_ADDRESS must be a mailbox in MAIL_DOMAIN")
    selector = os.environ.get("MAIL_DKIM_SELECTOR", "erecruit")
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}", selector):
        raise ValueError("MAIL_DKIM_SELECTOR must be a DNS label")
    network = ipaddress.ip_network(os.environ.get("MAIL_TRUSTED_SUBNET", "172.31.250.0/28"), strict=True)
    private_ranges = [ipaddress.ip_network(value) for value in ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")]
    if network.version != 4 or network.prefixlen < 24 or not any(network.subnet_of(private) for private in private_ranges):
        raise ValueError("MAIL_TRUSTED_SUBNET must be an RFC1918 IPv4 subnet /24 or smaller")
    return dict(domain=domain, hostname=hostname, sender=sender, selector=selector, network=str(network))


def configure(config: dict) -> Path:
    domain, selector = config["domain"], config["selector"]
    key_directory = Path("/var/lib/erecruit-mail/dkim") / domain / selector
    key_directory.mkdir(parents=True, exist_ok=True)
    user = pwd.getpwnam("opendkim")
    for directory in (Path("/var/lib/erecruit-mail"), key_directory.parent.parent, key_directory.parent, key_directory):
        os.chown(directory, user.pw_uid, user.pw_gid)
        directory.chmod(0o700)
    key = key_directory / f"{selector}.private"
    record = key_directory / f"{selector}.txt"
    if key.exists() != record.exists():
        raise ValueError("Incomplete DKIM keypair: restore the persistent DKIM volume; keys will not be overwritten")
    if not key.exists():
        subprocess.run(["opendkim-genkey", "-b", "2048", "-d", domain, "-s", selector, "-D", str(key_directory)], check=True)
    for file in (key, record):
        os.chown(file, user.pw_uid, user.pw_gid)
        file.chmod(0o600)
    Path("/etc/opendkim-trusted-hosts").write_text(f"127.0.0.1\n{config['network']}\n")
    Path("/etc/opendkim.conf").write_text(
        "Syslog no\nMode s\nCanonicalization relaxed/relaxed\nSignatureAlgorithm rsa-sha256\n"
        "Socket inet:8891@127.0.0.1\nUserID opendkim\nUMask 0077\nRequireSafeKeys yes\n"
        "InternalHosts /etc/opendkim-trusted-hosts\nOversignHeaders From\n"
        f"Domain {domain}\nSelector {selector}\nKeyFile {key}\n"
    )
    Path("/etc/postfix/allowed_senders").write_text(f"/^{re.escape(config['sender'])}$/ OK\n")
    subprocess.run(["postconf", "-e", f"myhostname={config['hostname']}", f"mydomain={domain}", f"myorigin={domain}", f"mynetworks=127.0.0.0/8, {config['network']}"], check=True)
    # Initialize new persistent queue volumes and repair ownership only via Postfix.
    subprocess.run(["postfix", "set-permissions"], check=True)
    subprocess.run(["newaliases"], check=True)
    subprocess.run(["postfix", "check"], check=True)
    subprocess.run(["opendkim", "-n", "-x", "/etc/opendkim.conf"], check=True)
    return record


def serve() -> int:
    signer = subprocess.Popen(["opendkim", "-f", "-x", "/etc/opendkim.conf"])
    postfix = None
    stopping = False

    def stop(_signum, _frame):
        nonlocal stopping
        stopping = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    try:
        for _ in range(50):
            if signer.poll() is not None:
                raise RuntimeError("DKIM signing service exited during startup")
            try:
                with socket.create_connection(("127.0.0.1", 8891), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.1)
        else:
            raise RuntimeError("DKIM signing service did not become ready")
        postfix = subprocess.Popen(["postfix", "start-fg"])
        while not stopping:
            if signer.poll() is not None or postfix.poll() is not None:
                raise RuntimeError("Mail service exited; stopping container to trigger supervised restart")
            time.sleep(0.5)
        return 0
    finally:
        if postfix is not None and postfix.poll() is None:
            subprocess.run(["postfix", "stop"], timeout=15, check=False)
        if signer.poll() is None:
            signer.terminate()
            signer.wait(timeout=10)


if __name__ == "__main__":
    try:
        config = configuration()
        command = sys.argv[1] if len(sys.argv) > 1 else "serve"
        if command not in {"serve", "check-config", "dkim-dns"}:
            raise ValueError("Supported commands: serve, check-config, dkim-dns")
        if command == "check-config":
            print("Mail environment is valid. DNS ownership, PTR, port 25 and delivery still require deployment checks.")
            sys.exit(0)
        record = configure(config)
        if command == "dkim-dns":
            print(f"Publish this TXT record under {config['domain']} (public key only):")
            print(record.read_text())
            sys.exit(0)
        sys.exit(serve())
    except (ValueError, RuntimeError, subprocess.SubprocessError, OSError) as error:
        print(f"Mail startup failed: {error}", file=sys.stderr)
        sys.exit(1)
