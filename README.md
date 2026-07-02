# Mini Bug Bounty Lab + Pentest Report

Mini Bug Bounty Lab is a local-only FastAPI security lab for practicing web/API pentesting and presenting a clean portfolio project for a pentest internship application.

It contains two versions of the same small ecommerce-style API:

- `app-vulnerable`: intentionally vulnerable implementation.
- `app-fixed`: hardened implementation with the same business features.

> Warning: this project is intentionally vulnerable. Run it only on your own local machine or an isolated lab network.

## Learning Goals

- Practice API testing with Swagger, Postman, Burp Suite and small Python checkers.
- Understand common web/API bugs and how to fix them.
- Compare vulnerable and fixed code side by side.
- Produce a professional pentest report with risk ratings, impact and retest results.

## Tech Stack

- Backend: Python FastAPI
- Database: SQLite
- Frontend: HTML + Bootstrap
- API docs: FastAPI Swagger/OpenAPI
- Runtime: Docker Compose

## Project Structure

```text
mini-bug-bounty-lab/
|-- app-vulnerable/
|-- app-fixed/
|-- docker-compose.yml
|-- README.md
|-- docs/
|   |-- pentest-report.md
|   |-- methodology.md
|   |-- vulnerability-matrix.md
|   `-- screenshots/
|-- scripts/
|   |-- api_idor_checker.py
|   |-- jwt_checker.py
|   `-- cors_checker.py
`-- postman/
    `-- mini-bug-bounty-lab.postman_collection.json
```

## Quick Start

From this directory:

```bash
docker compose up --build
```

Open:

- Vulnerable app: http://localhost:8000
- Vulnerable web UI: http://localhost:8000/login
- Vulnerable Swagger: http://localhost:8000/docs
- Fixed app: http://localhost:8001
- Fixed web UI: http://localhost:8001/login
- Fixed Swagger: http://localhost:8001/docs

Stop the lab:

```bash
docker compose down
```

Reset seeded databases:

```bash
docker compose down -v
docker compose up --build
```

## Sample Accounts

Vulnerable app:

| Role | Username | Password |
|---|---|---|
| user | alice | alice123 |
| user | bob | bob123 |
| staff | staff | staff123 |
| admin | admin | admin123 |

Fixed app:

| Role | Username | Password |
|---|---|---|
| user | alice | alice12345 |
| user | bob | bob12345 |
| staff | staff | staff12345 |
| admin | admin | admin12345 |

## Vulnerabilities in `app-vulnerable`

| ID | Vulnerability | Example Endpoint |
|---|---|---|
| VULN-01 | IDOR/BOLA | `GET /api/orders/{order_id}` |
| VULN-02 | Broken Access Control | `GET /api/admin/users` |
| VULN-03 | SQL Injection | `GET /api/feedback?search=` |
| VULN-04 | Stored XSS | `/feedback-wall` |
| VULN-05 | Insecure File Upload | `POST /api/avatar` |
| VULN-06 | Weak JWT Secret | `POST /api/login` |
| VULN-07 | Business Logic Flaw | `POST /api/checkout` |
| VULN-08 | CORS Misconfiguration | Any API route |
| VULN-09 | Missing Rate Limit | `POST /api/login` |
| VULN-10 | Information Disclosure | Unhandled errors |

## Web UI Demo Flow

The lab includes a Bootstrap web UI for browser-based demos:

- `/login` and `/register`
- `/dashboard`
- `/profile`
- `/orders`
- `/orders/{order_id}/view`
- `/avatar`
- `/feedback`
- `/checkout`
- `/admin`

Recommended demo:

1. Open `http://localhost:8000/login`.
2. Log in as `alice/alice123`.
3. Open `Orders`.
4. Use the order ID input and open order `3`.
5. The vulnerable app returns Bob's order to Alice.
6. Repeat on `http://localhost:8001/login` with `alice/alice12345`.
7. The fixed app blocks the same cross-user order access.

The web UI stores the JWT in browser `localStorage` for lab convenience, which also makes it easy to inspect requests in browser DevTools or Burp Suite.

## Testing with Swagger, Postman and Burp

Swagger:

1. Open `http://localhost:8000/docs`.
2. Call `POST /api/login` with `alice/alice123`.
3. Copy the token and click `Authorize`.
4. Test order, profile, feedback, upload and admin endpoints.

Postman:

1. Import `postman/mini-bug-bounty-lab.postman_collection.json`.
2. Run `Login Alice - Vulnerable` and `Login Bob - Vulnerable`.
3. Copy tokens into collection variables if your Postman version does not auto-store them.
4. Send the IDOR, admin and feedback requests.

Burp Suite:

1. Configure your browser to use Burp proxy, usually `127.0.0.1:8080`.
2. Log in through Swagger or Postman.
3. Intercept `GET /api/orders/1`.
4. Change the order ID to another user's order, such as `/api/orders/3`.
5. Forward the request and observe the vulnerable app returns another user's order.

## Running Checker Scripts

Install script dependencies locally:

```bash
cd scripts
python -m pip install -r requirements.txt
```

Get two vulnerable tokens:

```bash
curl -s -X POST http://localhost:8000/api/login -H "Content-Type: application/json" -d "{\"username\":\"alice\",\"password\":\"alice123\"}"
curl -s -X POST http://localhost:8000/api/login -H "Content-Type: application/json" -d "{\"username\":\"bob\",\"password\":\"bob123\"}"
```

Run IDOR checker:

```bash
python scripts/api_idor_checker.py http://localhost:8000 <alice_token> <bob_token>
python scripts/api_idor_checker.py http://localhost:8001 <alice_fixed_token> <bob_fixed_token>
```

Run JWT checker:

```bash
python scripts/jwt_checker.py <alice_token> --base-url http://localhost:8000
```

Run CORS checker:

```bash
python scripts/cors_checker.py http://localhost:8000
python scripts/cors_checker.py http://localhost:8001
```

## Manual IDOR Demo with curl

1. Log in as Alice:

```bash
ALICE_TOKEN=$(curl -s -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"alice","password":"alice123"}' | python -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

2. Log in as Bob and list Bob's orders:

```bash
BOB_TOKEN=$(curl -s -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"bob","password":"bob123"}' | python -c "import sys,json; print(json.load(sys.stdin)['access_token'])")

curl -s http://localhost:8000/api/orders -H "Authorization: Bearer $BOB_TOKEN"
```

3. Use Alice's token to access Bob's order, for example order ID `3`:

```bash
curl -i http://localhost:8000/api/orders/3 -H "Authorization: Bearer $ALICE_TOKEN"
```

Expected vulnerable result: HTTP `200` with `user_id: 2`.

Expected fixed result on port `8001`: HTTP `404` for the same cross-user access.

## Important Files

- `app-vulnerable/main.py`: vulnerable FastAPI app and seeded database logic.
- `app-fixed/main.py`: fixed FastAPI app with authorization, safe SQL, upload validation, rate limiting and safer errors.
- `docker-compose.yml`: runs both apps side by side.
- `scripts/`: lightweight security checkers for IDOR, JWT and CORS.
- `docs/pentest-report.md`: professional sample report.
- `docs/vulnerability-matrix.md`: quick mapping of vulnerabilities to OWASP categories.
- `postman/mini-bug-bounty-lab.postman_collection.json`: importable API test collection.
