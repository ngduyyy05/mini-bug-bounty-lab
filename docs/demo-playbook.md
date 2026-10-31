# Five-minute interview demo

## 0:00 - Context and scope

Explain: "I built a synthetic ecommerce API in vulnerable and remediated forms to demonstrate how I test authorization and validate fixes." Start both local services with `docker compose up --build`. Open localhost:8000.

## 0:45 - Show a reproducible finding

On the workbench select VULN-01. Describe the boundary: Alice is authenticated, but does not own Bob's order. Run the live check. Show the positive control and the unauthorized response. Export the JSON as evidence. Explain that the impact is cross-customer purchase disclosure; do not claim account takeover from this evidence.

## 1:45 - Show the fix

Open localhost:8001 and repeat. The owner request still returns 200, while Bob's order returns 404. Compare `order_detail` in the two main.py files: the fixed query scopes the object by owner, with an explicit admin exception.

## 2:45 - Show regression evidence

Run `python -m pytest -q` after installing requirements-dev.txt. Explain why checking both apps matters: an exploit test that fails everywhere can hide a broken login or invalid payload. Show tests for pricing, injection and authorization, then open the CI workflow.

## 3:45 - Discuss tradeoffs

Use docs/security-boundaries.md. Discuss why extension/MIME upload checks are incomplete, why localStorage JWTs deserve a session-design review, and why a local throttle does not scale across workers. Acknowledge what the suite does not prove.

## 4:30 - Close with next steps

Show docs/pentest-report.md as a sample report. Describe a next improvement you can defend technically: image re-encoding, shared rate limiting, or a complete role/object access matrix. Keep all claims tied to this lab and actual test artifacts.
