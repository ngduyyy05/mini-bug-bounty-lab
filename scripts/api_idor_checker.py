#!/usr/bin/env python3
import argparse
import sys

import requests


def headers(token):
    return {"Authorization": f"Bearer {token}"}


def get_json(url, token):
    resp = requests.get(url, headers=headers(token), timeout=10)
    try:
        body = resp.json()
    except ValueError:
        body = resp.text
    return resp.status_code, body


def main():
    parser = argparse.ArgumentParser(description="Lightweight IDOR/BOLA checker for Mini Bug Bounty Lab.")
    parser.add_argument("base_url", help="Example: http://localhost:8000")
    parser.add_argument("token_user_a", help="Bearer token value for user A, without the Bearer prefix")
    parser.add_argument("token_user_b", help="Bearer token value for user B, without the Bearer prefix")
    args = parser.parse_args()

    base = args.base_url.rstrip("/")

    status_b_orders, b_orders = get_json(f"{base}/api/orders", args.token_user_b)
    if status_b_orders != 200 or not isinstance(b_orders, list) or not b_orders:
        print(f"FAIL: Could not fetch user B orders. HTTP {status_b_orders}: {b_orders}")
        return 2

    b_order_id = b_orders[0]["id"]
    status_idor, order_body = get_json(f"{base}/api/orders/{b_order_id}", args.token_user_a)
    if status_idor == 200 and isinstance(order_body, dict) and order_body.get("user_id") == b_orders[0].get("user_id"):
        print(f"FAIL: IDOR/BOLA detected. User A accessed user B order id {b_order_id}.")
    elif status_idor in (403, 404):
        print(f"PASS: User A could not access user B order id {b_order_id}. HTTP {status_idor}.")
    else:
        print(f"WARN: Unexpected order test response. HTTP {status_idor}: {order_body}")

    status_profile, profile_body = get_json(f"{base}/api/profile/2", args.token_user_a)
    if status_profile == 200 and isinstance(profile_body, dict) and profile_body.get("id") == 2:
        print("FAIL: Profile IDOR detected. User A accessed /api/profile/2.")
        return 1
    if status_profile in (403, 404):
        print(f"PASS: User A could not access /api/profile/2. HTTP {status_profile}.")
        return 0

    print(f"WARN: Unexpected profile test response. HTTP {status_profile}: {profile_body}")
    return 1


if __name__ == "__main__":
    sys.exit(main())
