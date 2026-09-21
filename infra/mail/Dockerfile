FROM debian:bookworm-slim

ENV DEBIAN_FRONTEND=noninteractive
COPY dpkg-options /etc/dpkg/dpkg.cfg.d/zz-erecruit-mail
RUN apt-get update \
    && apt-get install -y --no-install-recommends postfix opendkim opendkim-tools ca-certificates python3 dnsutils \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.py healthcheck.py /opt/erecruit-mail/
COPY main.cf master.cf /etc/postfix/

EXPOSE 25
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 CMD ["python3", "/opt/erecruit-mail/healthcheck.py"]
ENTRYPOINT ["python3", "/opt/erecruit-mail/entrypoint.py"]
