#!/usr/bin/env python3
import argparse
import sys

import requests


def main():
    parser = argparse.ArgumentParser(description="Check whether a target reflects or allows a hostile CORS origin.")
    parser.add_argument("base_url", help="Example: http://localhost:8000")
    parser.add_argument("--origin", default="https://evil.com")
    args = parser.parse_args()

    resp = requests.get(
        f"{args.base_url.rstrip('/')}/api/me",
        headers={"Origin": args.origin},
        timeout=10,
    )
    allowed_origin = resp.headers.get("Access-Control-Allow-Origin")
    allow_credentials = resp.headers.get("Access-Control-Allow-Credentials")

    print(f"INFO: Access-Control-Allow-Origin: {allowed_origin}")
    print(f"INFO: Access-Control-Allow-Credentials: {allow_credentials}")

    if allowed_origin in ("*", args.origin):
        print("FAIL: CORS misconfiguration detected for hostile origin.")
        return 1

    print("PASS: Hostile origin was not allowed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
