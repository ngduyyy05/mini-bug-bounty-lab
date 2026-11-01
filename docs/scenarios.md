# PHP vulnerability walkthroughs

Start the stack using the README. Each proof below runs an identical HTTP scenario against both independent applications, includes valid-action controls and asserts the different security outcome. Run `docker compose --profile test build tests` once, then the command shown for each finding. Payloads target only the synthetic local services. The implementation is in each application's `src/Application.php` unless noted otherwise.

JSON evidence is written to `artifacts/testNN*-vulnerable.json` and `artifacts/testNN*-fixed.json`; `docker compose run --rm tests` also writes the assertion results to `artifacts/security-regression.xml`. A JSON observation alone is not a passing result. Repeated runs add synthetic feedback, users, orders and upload markers. The test runner resets only fixed throttle state before each test to isolate scenarios. Do not run suites in parallel against the same databases.

## 01 — IDOR / BOLA

**PoC:** `docker compose run --rm tests php scripts/poc.php 01`

Log in as Alice, then request `GET /api/orders/1` (owner control) and `GET /api/orders/3` (Bob's order). Also request `GET /api/profile/2` and `PUT /api/profile/2` with `{"full_name":"Bob Tran"}`. The write uses the existing name so the ownership test does not change Bob's normal profile content.

**Vulnerable PHP:** `Database::query($db, 'SELECT * FROM orders WHERE id = ?', [$id])` binds the object ID safely but never checks the caller's identity. Profile read/write routes similarly accept any ID after authentication. SQL injection prevention does not provide object authorization.

**Root cause / impact:** a user-controlled identifier selects another customer's data. Any authenticated customer can read orders and read or alter another profile.

**Fixed PHP:** non-admin queries include `WHERE id = ? AND user_id = ?`. Profile reads and writes compare the target ID with the authenticated user ID, permitting an explicit admin exception. The identity comes from a validated token and database user.

**Verify:** own order/profile 200 in both; cross-user order 200 vulnerable / 404 fixed; cross-user profile reads/writes 200 / 403; missing order 404; anonymous order 401. Admin can still read Bob's order and profile.

## 02 — Broken access control

**PoC:** `docker compose run --rm tests php scripts/poc.php 02`

Call `GET /api/admin/users` with Alice's token, then a staff token; repeat as admin with `?search=alice`.

**Vulnerable PHP:** the admin route calls the search query after `$this->auth->user()` without a role guard. A menu label or hidden frontend link does not enforce authorization.

**Root cause / impact:** authentication is mistaken for permission to use privileged functions, exposing customer records to ordinary users and staff.

**Fixed PHP:** `if ($user['role'] !== 'admin') { throw new HttpError(403, 'Insufficient role'); }` runs before accessing privileged data. The role is loaded from the database; request JSON and modified token roles cannot grant permission.

**Verify:** Alice and staff get 200 vulnerable / 403 fixed; admin search remains 200 in both and does not disclose password hashes.

## 03 — SQL injection

**PoC:** `docker compose run --rm tests php scripts/poc.php 03`

Search `/api/feedback?search=unmatched_lab_marker` first. Then URL-encode and submit `unmatched_lab_marker' OR 1=1 -- ` (keep the space after `--`). Repeat on `/api/admin/users?search=` as admin, so role authorization does not obscure the SQL boundary.

**Vulnerable PHP:** `$sql = "SELECT ... WHERE message LIKE '%$search%'"; $db->query($sql);`. Admin search also interpolates `$search` into quoted SQL.

**Root cause / impact:** attacker-controlled input becomes SQL syntax. A tautology bypasses the intended filter and demonstrates control over the query predicate. The local MySQL account has permissions on its own synthetic database only; multi-statements are disabled, which does not prevent this single-statement injection.

**Fixed PHP:** `Database::query($db, 'SELECT ... WHERE message LIKE ?', ["%$search%"])` uses native PDO prepared statements. The admin query binds each LIKE value separately. No escaping-based SQL construction is reused from the vulnerable route.

**Verify:** ordinary search returns seeded feedback in both; unmatched search returns `[]`; the injection returns rows only in vulnerable. The same text is treated as a literal search pattern in fixed.

## 04 — Stored XSS

**PoC:** `docker compose run --rm tests php scripts/poc.php 04`

Post `{"message":"<script>window.labXssMarker=1</script>"}` to `/api/feedback`, then visit `/feedback-wall`. For manual browser confirmation on the vulnerable app, open DevTools and inspect `window.labXssMarker` after loading the wall.

**Vulnerable PHP:** `templates/feedback.php` emits `<?= $item["message"] ?>`. Stored input is inserted into an HTML parsing context without encoding.

**Root cause / impact:** an author can cause script to run for visitors to a shared page. The marker changes only a page-local variable. It demonstrates the execution sink without exfiltrating data.

**Fixed PHP:** `Http::escape($item['message'])` calls `htmlspecialchars` with quote encoding and UTF-8 substitution. Store raw data; encode at the specific output boundary. The JavaScript application also escapes text before constructing HTML.

**Verify:** the HTTP test sees literal script markup only in vulnerable, and `&lt;script&gt;` in fixed. The suite validates emitted markup, not an automated browser execution trace. Plain feedback continues to render in both.

## 05 — Insecure file upload

**PoC:** `docker compose run --rm tests php scripts/poc.php 05`

First upload a real one-pixel PNG using multipart field `file`, then retrieve its returned URL. Next upload a randomly named `.php` file containing only `<?php echo "LAB_UPLOAD_EXECUTED"; ?>`, with a misleading declared `image/png` MIME type. Fetch the returned URL if accepted. Also try a fake PNG and a file over 1 MB.

**Vulnerable PHP:** `$name = $file['name']; move_uploaded_file($file['tmp_name'], ... '/public/uploads/' . $name)`. Neither extension nor content is validated. Apache executes PHP files in that public directory. This intentionally preserves an unsafe client filename and executable-storage design for the lesson.

**Root cause / impact:** untrusted upload metadata determines storage and execution context. The echo marker proves PHP code execution within this disposable application container; it does not run operating-system commands.

**Fixed PHP:** inspect extension, fileinfo MIME, image structure, byte size and dimensions; decode with GD and re-encode PNG. Generate a random 128-bit filename and write outside the document root at `/var/lib/lab/uploads`. A constrained controller serves only matching random image names with image MIME, `nosniff` and sandbox headers. Appended PHP bytes are discarded during re-encoding.

**Verify:** genuine images upload and download in both. PHP and fake-image files are accepted vulnerable / rejected 400 fixed; oversized files are accepted vulnerable / rejected 413 fixed. Appended PHP text is absent from the fixed downloaded PNG. The harmless PHP echo executes only from vulnerable web storage.

The scenario 05 runner also covers an upload → path traversal → LFI → PHP execution chain. It appends `<?php echo "LAB_LFI_" . (6 * 7); ?>` to a valid one-pixel PNG and submits multipart `folder=../drafts`. The vulnerable uploader writes it outside `public/uploads/`, into `public/drafts/`. `GET /api/help?article=../../public/drafts/<name>.png` then includes that file from the help article directory. The marker `LAB_LFI_42` proves PHP was evaluated, even with a `.png` extension. Direct static image delivery does not evaluate the PHP.

The fixed uploader rejects that folder with 400, re-encodes accepted images and stores them outside the web root. Its help controller accepts only `shipping.php` and `returns.php`, returning 404 for traversal. The test also tries including an ordinary accepted upload to verify that the article allowlist works independently of the upload fix. Named-folder image uploads and both normal articles remain available in both apps.

## 06 — Weak JWT secret

**PoC:** `docker compose run --rm tests php scripts/poc.php 06`

After a valid Alice login and `/api/me` control, sign an HS256 JWT using `secret` with `sub: "4"`, `role: "admin"`, current `iat`, future `exp`, issuer `mini-bbl-fixed` and audience `mini-bbl`. Send it as a bearer token to `/api/me` on both apps. The source test contains the complete base64url/HMAC construction.

**Vulnerable PHP:** `src/Auth.php` embeds `$secret = 'secret'`. Its isolated minimal HS256 codec still checks signatures and expiry, but anyone knowing the dictionary key can mint a valid signature for any seeded subject.

**Root cause / impact:** integrity depends on signing-key secrecy and strength. A known short key permits impersonation of admin user ID 4. Merely modifying a role claim is weaker evidence: both apps load the actual user role from the database.

**Fixed PHP:** uses the maintained JWT library, a randomly generated external secret, a pinned HS256 algorithm, required typed claims, issuer/audience checks, issue/expiry constraints and database role consistency. No weak-key fallback is present. Configuration fails closed for short or repetitive keys.

**Verify:** valid Alice tokens work in both; the forged token returns admin identity only in vulnerable, and 401 fixed. Additional fixed tests sign invalid claims with the actual test secret, proving that claims are checked after cryptographic validation; missing claims, expired/future tokens, wrong issuer/audience, role mismatch, algorithm changes and `none` are rejected.

## 07 — Business logic flaw

**PoC:** `docker compose run --rm tests php scripts/poc.php 07`

Post `/api/checkout` with `{"product_id":1,"quantity":1,"amount":0.01}`. Repeat with quantity 0, -1 and 21. First submit quantity 2 and amount 39.98 as a legitimate control.

**Vulnerable PHP:** `$total = (float) $data['amount'];` passes the client price into the paid order. Quantity is an integer but has no business constraints.

**Root cause / impact:** the client is trusted to enforce pricing and purchase rules; a user can underpay or create invalid quantities.

**Fixed PHP:** load product price from MySQL DECIMAL, convert to integer cents, multiply by a server-validated quantity 1–20, and persist the calculated total. A supplied `amount` is ignored to preserve practical request compatibility.

**Verify:** quantity 2 costs 39.98 in both. Tampered amount charges 0.01 vulnerable / 19.99 fixed; invalid quantities succeed vulnerable / return 422 fixed. Unknown products return 404 in both. Normal order listing and detail still work.

## 08 — CORS misconfiguration

**PoC:** `docker compose run --rm tests php scripts/poc.php 08`

Send `OPTIONS /api/me` with `Origin: https://untrusted.invalid`, `Access-Control-Request-Method: GET` and requested `Authorization` header. Repeat with trusted `http://localhost:8001`. Inspect an actual authenticated GET with the hostile origin as well.

**Vulnerable PHP:** every supplied origin is reflected in `Access-Control-Allow-Origin` alongside `Access-Control-Allow-Credentials: true` and allowed authorization headers.

**Root cause / impact:** arbitrary websites are granted browser cross-origin read permission. This broadens exposure when an attacker can cause a browser to send appropriate credentials. CORS alone does not steal a bearer token from localStorage, and the current client does not attach ambient auth cookies.

**Fixed PHP:** compare origins exactly against `ALLOWED_ORIGINS`; emit permission headers only for trusted origins. Preflight rejects untrusted origins or unsupported methods. Responses vary by Origin.

**Verify:** hostile preflight 200 with reflected origin vulnerable / 400 without allow-origin fixed. Trusted preflight succeeds in both. Authenticated GET remains 200 but has no hostile allow-origin in fixed. CORS is not server-side authentication.

## 09 — Missing rate limiting

**PoC:** `docker compose run --rm tests php scripts/poc.php 09`

After one successful login, submit four bad logins for a missing account, then another under a rotated username. The fixed limiter counts successful and failed reservations alike, so the sixth total attempt is throttled.

**Vulnerable PHP:** `src/Auth.php::login` directly checks credentials each time, with no shared state or budget.

**Root cause / impact:** repeated guessing consumes authentication work without a server-enforced limit.

**Fixed PHP:** compute account and actual remote-IP keys; use MySQL/InnoDB transactions, row locks and atomic increments to reserve capacity before password verification. Deny after five attempts in 60 seconds with 429 and Retry-After. The database is shared across Apache workers. Keys are sorted to maintain lock order. Requests cannot reset budgets via successful login or username rotation.

**Verify:** ordinary login succeeds; vulnerable attempts stay 401; fixed reaches 429. Additional fixed tests run 12 concurrent bad requests and assert exactly five 401 and seven 429, then simulate expiry in the test database and confirm valid login works again. Test-only database resets are never exposed as application endpoints.

## 10 — Information disclosure

**PoC:** `docker compose run --rm tests php scripts/poc.php 10`

Register the already seeded username `alice` with a valid-length password/email. Also search feedback for the literal single quote `'`.

**Vulnerable PHP:** `public/index.php` catches unexpected exceptions and serializes the message, stack trace and database DSN. Duplicate registration triggers a PDO integrity exception; an unmatched quote triggers a PDO SQL syntax error through vulnerable search construction.

**Root cause / impact:** diagnostic details cross the API trust boundary, revealing SQL, schema fragments, paths and internal topology useful for follow-on analysis. The lab DSN contains no database password; the scenario still demonstrates sensitive internals being exposed.

**Fixed PHP:** duplicate usernames become a deliberate 409 conflict; prepared search treats a quote as data. The global exception boundary logs diagnostics server-side and returns only `{"detail":"Internal server error"}` for unexpected failures. PHP display_errors and expose_php are disabled.

**Verify:** duplicate registration returns a verbose 500 vulnerable / safe 409 fixed. Quote search returns verbose SQLSTATE 500 vulnerable / normal 200 fixed. Fixed responses contain no trace or DSN. The fixed unexpected-error boundary is additionally verified using a failing database configuration in an isolated PHP process.

## 11 — Server-side template injection

**PoC:** `docker compose run --rm tests php scripts/poc.php 11`

The gift-card form at `/gift-card` posts `recipient` and `template` to `/api/gift-card/preview`. A normal message such as `Hello {{ recipient }} from {{ sender }}` substitutes the two names in both versions. Send `{{ 7 * 7 }}` in the template field: vulnerable returns `49`, while fixed retains the literal expression. A Twig loop over `1..3` similarly returns `123` only in vulnerable.

**Vulnerable PHP:** `src/CustomerTools.php::gift()` passes the customer's message directly to Twig's `createTemplate()` and renders it without sandboxing. The customer controls template source rather than just values.

**Fixed PHP:** only the two supported name placeholders are replaced with a single callback pass. No template engine evaluates the message. A recipient containing template syntax remains literal in both implementations. The browser uses `textContent` to display the preview, so preview HTML does not become a separate XSS surface.

**Verify:** normal substitutions work in both; arithmetic and template control flow execute only in vulnerable; invalid recipient types return 422; unauthenticated previews return 401. These checks establish SSTI, not operating-system command execution through Twig.

## 12 — Server-side request forgery

**PoC:** `docker compose run --rm tests php scripts/poc.php 12`

The shipment page at `/shipping` posts a carrier URL to `/api/shipping/track`. `http://delivery-service/tracking/DEMO1001` is a legitimate control that works in both versions. Each app has a separate synthetic delivery container, on its own network with the same DNS alias and no published host port.

**Vulnerable PHP:** `src/CustomerTools.php::tracking()` permits arbitrary HTTP/HTTPS destinations and follows redirects. Requesting `http://delivery-service/internal/dispatch` reveals the synthetic `local-dispatch-demo-token`. `/tracking/REDIRECT1` redirects to that internal route. `http://127.0.0.1/health` demonstrates a request into the application server's loopback interface. The provider contains only dummy data; tests contact no external target.

**Fixed PHP:** an exact URL format allows only the Docker-managed `delivery-service` hostname and a bounded `/tracking/` identifier. Credentials, alternate ports, queries, fragments, encoded paths and alternate hosts are excluded. Redirects are not followed and non-200 responses are rejected. Both apps use a three-second request timeout and 64 KiB response cap.

**Verify:** a normal shipment returns 200 in both. The internal endpoint and loopback URL return 200 vulnerable / 422 fixed; redirect-based access returns 200 / 502. Fixed tests also reject IPv6/numeric loopback forms, hostname suffixes, userinfo, traversal and query-based bypass attempts. Non-HTTP schemes return 422 in both; unauthenticated tracking returns 401.
