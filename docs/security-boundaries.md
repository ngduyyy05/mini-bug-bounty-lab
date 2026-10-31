# Security boundaries and residual risk

This is a deliberately vulnerable, synthetic training project. The fixed app is a teaching comparison, not a production-ready security guarantee. Compose publishes both services on loopback only. Do not expose either instance through a public tunnel.

## Evidence levels

- The finding register describes seeded weaknesses and proposed controls.
- The browser IDOR demo makes live requests and exports observations with a timestamp. It checks a legitimate owner request first; unexpected results are inconclusive.
- The automated suite uses fresh temporary databases for each case and tests both implementations. A passing differential test means the asserted behavior matched expectations, including exploitation of the vulnerable app.
- Stored HTML tests verify output escaping, not JavaScript execution in a real browser.
- CORS preflight tests prove origin policy behavior, not credential theft. Wildcard responses do not automatically imply an exploitable credentialed cross-origin read.
- Upload checks cover a disallowed extension, not image decoding, polyglots, or malware detection.
- JWT forgery and debug-error disclosure remain manual review cases; do not describe them as covered by the automated suite.

## Remaining limitations in app-fixed

| Area | Current behavior | Production follow-up |
|---|---|---|
| JWT | Development fallback secret; two-hour token lifetime | Require a deployment secret, rotation strategy and issuer/audience constraints |
| Browser session | JWT in localStorage; logout deletes only client token | Design session cookies/CSRF controls or a deliberate token storage model; support revocation |
| Uploads | Extension, declared MIME and size checks | Decode and re-encode actual image content; isolate serving origin and storage |
| Throttling | Per-username in-memory counters | Shared atomic store, bounded retention, source-aware controls and monitoring |
| Transport | Local HTTP | TLS and deployment-specific browser headers |
| Data integrity | SQLite and floating-point prices | Integer minor currency units, transactions, constraints and concurrency tests |
| Operations | Demo credentials and no audit pipeline | Real identity lifecycle, structured audit events and alerting |
| Dependencies | Original pinned application dependencies | Separate dependency/advisory review before deployment |

Severity labels are qualitative lab estimates, not independently scored CVSS assessments. No real customer data, production assessment, external bounty, or commercial engagement is implied.
