# Five-minute PHP security demo

## 0:00 — Start and scope

This document is presenter solution material, separate from the public website. Follow the README setup, then `docker compose up -d --build --wait`. Open localhost:8000. The storefront exposes normal shopping functions without lessons or payload hints. Describe the synthetic shop, two independent PHP applications, separate MySQL databases and loopback-only scope.

## 0:45 — Reproduce IDOR

Sign in as Alice and open one of her orders. Inspect the order request with your own HTTP tools, then request Bob's order 3. Alice's own order returns 200; Bob's order also returns 200 in the vulnerable app. Explain the consequence: cross-customer purchase disclosure. The evidence does not establish account takeover.

## 1:45 — Show the PHP remediation

Open localhost:8001/fixes to locate the remediation, then repeat at localhost:8001. Alice's own order remains available; order 3 returns 404. Compare the order route in each `src/Application.php`: the fixed query binds both order ID and owner ID, with an explicit admin exception. Profile reads and writes enforce the same trust boundary.

## 2:45 — Run paired evidence

```sh
docker compose --profile test build tests
docker compose run --rm tests
docker compose run --rm tests php scripts/poc.php 06
```

Show PHPUnit's result, `artifacts/security-regression.xml`, and the JSON request/response evidence. Scenario 06 signs an admin **subject**, not merely an admin role, using the known weak key. `/api/me` proves impersonation in the vulnerable app and returns 401 in the fixed app. Positive controls distinguish blocked exploitation from broken authentication.

## 3:45 — Explain stronger PHP controls

Compare SQL interpolation with native PDO parameters. Explain `htmlspecialchars` at the HTML output boundary. Demonstrate a valid image upload and a harmless PHP echo marker: vulnerable uploads execute; fixed uploads reject that file and re-encode real images outside Apache's document root. Show account/IP rate limits stored atomically in MySQL across Apache workers, including concurrent-request tests.

## 4:30 — State the limits

Open `docs/security-boundaries.md` and the sample report. Discuss localStorage tokens, expiry-based logout, single-host deployment and browser-specific verification. The XSS test proves executable markup is emitted; it is not a browser automation run. Keep all claims tied to recorded observations and successful assertions.
