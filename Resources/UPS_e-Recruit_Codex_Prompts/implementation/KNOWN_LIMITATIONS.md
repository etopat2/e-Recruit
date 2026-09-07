# Known Limitations and External Dependencies

## Explicitly deferred by the specification

- UNEB integration: excluded; must not be introduced.
- NIRA verification: optional future capability requiring lawful approval and access.
- USSD and native mobile applications: not required for v1.0.
- Facial recognition, blockchain, payments, AI suitability scoring, microservices and Kubernetes dependency: excluded.

## Production inputs not available in this workspace

- Authoritative Uganda administrative/jurisdiction dataset and approved campaign rules.
- Production SMTP/SMS/push credentials and contracts.
- Government hosting certificates, approved backup target and stakeholder-approved RPO/RTO.
- Formal UPS signatories, letterhead wording, privacy/retention policy, centre selection and final colour references.

Synthetic/demo values will be visibly labelled and cannot be represented as production policy.

## Residual release gates

- The automated evidence was produced on a developer workstation with synthetic data. Approved staging must repeat load/soak/OCR throughput and restore tests at the agreed 50k/150k scale and record infrastructure capacity.
- Automated axe coverage does not replace manual keyboard, screen-reader, reflow/zoom and user-language validation.
- OWASP baseline/dependency/container scanning does not replace an independent authenticated penetration test or DPIA.
- The malware interface and failure behavior are implemented; an enabled ClamAV service and EICAR exercise remain staging requirements.
- Offline encryption, expiry, idempotency and two-context conflict handling are tested in software; managed-device and real-network field drills remain required.
- Production is a NO-GO until the owners in `docs/deployment/GO_LIVE_CHECKLIST.md` provide evidence and signatures.
