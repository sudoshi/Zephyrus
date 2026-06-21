#!/usr/bin/env python3
"""
Idempotently provision the `zephyrus-oidc` application in Authentik.

Creates:
  - OAuth2/OpenID provider "Zephyrus OIDC"
  - Application slug "zephyrus-oidc" linked to the provider
  - The "Zephyrus Users" and "Zephyrus Admins" groups (if missing)
  - openid/email/profile scope mappings + a `groups` claim mapping
  - Binds both groups to the application (policy_engine_mode=any) so only
    members of either group can authenticate (the backend
    OidcReconciliationService re-enforces group membership server-side)

Prints the generated client_id and client_secret. These are NOT written to any
file by this script — copy them into /var/www/Zephyrus/.env.

Token: pass --token, or set AUTHENTIK_TOKEN, e.g.
  AUTHENTIK_TOKEN=$(docker exec acropolis-authentik-server printenv AUTHENTIK_BOOTSTRAP_TOKEN) \
    python3 scripts/authentik/provision_zephyrus_oidc.py
"""

from __future__ import annotations

import argparse
import json
import os
import secrets
import string
import sys
import urllib.error
import urllib.request

APP_SLUG = "zephyrus-oidc"
APP_NAME = "Zephyrus OIDC"
REDIRECT_URI = "https://zephyrus.acumenus.net/auth/oidc/callback"
REQUIRED_GROUPS = ["Zephyrus Users", "Zephyrus Admins"]
DEFAULT_AUTH_URL = "https://auth.acumenus.net"
GROUPS_MAPPING_NAME = "Zephyrus: OAuth2 groups claim"


class AuthentikAPI:
    def __init__(self, base_url: str, token: str) -> None:
        self.base_url = base_url.rstrip("/")
        self.token = token

    def _request(self, method: str, path: str, body: dict | None = None) -> dict:
        url = f"{self.base_url}{path}"
        data = None
        headers = {"Authorization": f"Bearer {self.token}", "Accept": "application/json"}
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=data, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                raw = resp.read()
                return json.loads(raw) if raw else {}
        except urllib.error.HTTPError as e:
            body_text = e.read().decode("utf-8", "replace")
            raise SystemExit(f"HTTP {e.code} on {method} {path}: {body_text[:500]}") from e

    def get(self, path: str) -> dict:
        return self._request("GET", path)

    def post(self, path: str, body: dict) -> dict:
        return self._request("POST", path, body)

    def patch(self, path: str, body: dict) -> dict:
        return self._request("PATCH", path, body)


def q(s: str) -> str:
    return urllib.request.quote(s, safe="")


def find_flow_pk(api: AuthentikAPI, designation: str, prefer_slug: str) -> str:
    flows = api.get(f"/api/v3/flows/instances/?designation={designation}&page_size=50").get("results", [])
    for flow in flows:
        if prefer_slug in flow.get("slug", ""):
            return flow["pk"]
    if not flows:
        raise SystemExit(f"No {designation} flows found in Authentik")
    return flows[0]["pk"]


def find_or_create_groups_mapping(api: AuthentikAPI) -> str:
    results = api.get("/api/v3/propertymappings/provider/scope/?page_size=200").get("results", [])
    for pm in results:
        if pm.get("scope_name") == "groups" or pm.get("name") == GROUPS_MAPPING_NAME:
            return pm["pk"]
    expression = 'return {\n    "groups": [group.name for group in request.user.ak_groups.all()],\n}\n'
    created = api.post(
        "/api/v3/propertymappings/provider/scope/",
        {
            "name": GROUPS_MAPPING_NAME,
            "scope_name": "groups",
            "description": "Emits a `groups` claim with the user's Authentik group names for Zephyrus JIT gating.",
            "expression": expression,
        },
    )
    return created["pk"]


def find_scope_mappings(api: AuthentikAPI) -> list[str]:
    wanted = {
        "goauthentik.io/providers/oauth2/scope-openid": None,
        "goauthentik.io/providers/oauth2/scope-email": None,
        "goauthentik.io/providers/oauth2/scope-profile": None,
    }
    results = api.get(
        "/api/v3/propertymappings/all/?page_size=200&managed__startswith=goauthentik.io/providers/oauth2/"
    ).get("results", [])
    for pm in results:
        managed = pm.get("managed") or ""
        if managed in wanted:
            wanted[managed] = pm["pk"]
    missing = [k for k, v in wanted.items() if v is None]
    if missing:
        raise SystemExit(f"Missing required OIDC scope mappings: {missing}")
    pks = [v for v in wanted.values() if v is not None]
    pks.append(find_or_create_groups_mapping(api))
    return pks


def find_signing_key(api: AuthentikAPI) -> str | None:
    certs = api.get("/api/v3/crypto/certificatekeypairs/?page_size=50").get("results", [])
    for cert in certs:
        if "Self-signed" in (cert.get("name") or "") and cert.get("private_key_available"):
            return cert["pk"]
    for cert in certs:
        if cert.get("private_key_available"):
            return cert["pk"]
    return None


def ensure_group(api: AuthentikAPI, name: str) -> str:
    groups = api.get(f"/api/v3/core/groups/?name={q(name)}&page_size=10").get("results", [])
    for g in groups:
        if g.get("name") == name:
            return g["pk"]
    return api.post("/api/v3/core/groups/", {"name": name})["pk"]


def find_existing_provider(api: AuthentikAPI, name: str) -> dict | None:
    for p in api.get("/api/v3/providers/oauth2/?page_size=200").get("results", []):
        if p.get("name") == name:
            return p
    return None


def find_existing_app(api: AuthentikAPI, slug: str) -> dict | None:
    for a in api.get(f"/api/v3/core/applications/?slug={slug}").get("results", []):
        if a.get("slug") == slug:
            return a
    return None


def bind_group_policy(api: AuthentikAPI, app_pk: str, group_pk: str) -> None:
    bindings = api.get(f"/api/v3/policies/bindings/?target={app_pk}&page_size=50").get("results", [])
    for b in bindings:
        if b.get("group") == group_pk:
            return
    api.post(
        "/api/v3/policies/bindings/",
        {"target": app_pk, "group": group_pk, "order": 0, "enabled": True, "negate": False},
    )


def generate_secret(length: int) -> str:
    alphabet = string.ascii_letters + string.digits
    return "".join(secrets.choice(alphabet) for _ in range(length))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=DEFAULT_AUTH_URL)
    parser.add_argument("--token", default=os.environ.get("AUTHENTIK_TOKEN", ""))
    args = parser.parse_args()

    if not args.token:
        raise SystemExit("No token. Pass --token or set AUTHENTIK_TOKEN.")

    api = AuthentikAPI(args.base_url, args.token)
    print(f"-> Authentik: {args.base_url}  app: {APP_SLUG}  redirect: {REDIRECT_URI}")

    print("1/6  Flows...")
    auth_flow_pk = find_flow_pk(api, "authorization", "default-provider-authorization")
    inval_flow_pk = find_flow_pk(api, "invalidation", "default-provider-invalidation")

    print("2/6  Scope mappings (openid, email, profile, groups)...")
    scope_mapping_pks = find_scope_mappings(api)

    print("3/6  Signing key...")
    signing_key_pk = find_signing_key(api)
    if not signing_key_pk:
        print("     WARNING: no signing keypair found — tokens would be unsigned")

    print("4/6  Groups...")
    group_pks = {name: ensure_group(api, name) for name in REQUIRED_GROUPS}

    print("5/6  Provider...")
    provider = find_existing_provider(api, APP_NAME)
    redirect_uris = [{"matching_mode": "strict", "url": REDIRECT_URI}]
    if provider:
        api.patch(
            f"/api/v3/providers/oauth2/{provider['pk']}/",
            {"redirect_uris": redirect_uris, "property_mappings": scope_mapping_pks},
        )
        client_id = provider.get("client_id", "")
        client_secret = provider.get("client_secret", "")
        print(f"     exists (pk={provider['pk']}) — patched")
    else:
        client_id = generate_secret(40)
        client_secret = generate_secret(64)
        payload: dict = {
            "name": APP_NAME,
            "authorization_flow": auth_flow_pk,
            "invalidation_flow": inval_flow_pk,
            "client_type": "confidential",
            "client_id": client_id,
            "client_secret": client_secret,
            "redirect_uris": redirect_uris,
            "property_mappings": scope_mapping_pks,
            "access_code_validity": "minutes=1",
            "access_token_validity": "minutes=10",
            "refresh_token_validity": "days=30",
            "sub_mode": "hashed_user_id",
            "include_claims_in_id_token": True,
        }
        if signing_key_pk:
            payload["signing_key"] = signing_key_pk
        provider = api.post("/api/v3/providers/oauth2/", payload)
        print(f"     created (pk={provider['pk']})")

    print("6/6  Application + group bindings...")
    app = find_existing_app(api, APP_SLUG)
    if app:
        if app.get("provider") != provider["pk"]:
            api.patch(f"/api/v3/core/applications/{APP_SLUG}/", {"provider": provider["pk"]})
        print(f"     app exists (pk={app['pk']})")
    else:
        app = api.post(
            "/api/v3/core/applications/",
            {
                "name": APP_NAME,
                "slug": APP_SLUG,
                "provider": provider["pk"],
                "meta_launch_url": "https://zephyrus.acumenus.net/",
                "policy_engine_mode": "any",
                "open_in_new_tab": False,
            },
        )
        print(f"     app created (pk={app['pk']})")
    for name, pk in group_pks.items():
        bind_group_policy(api, app["pk"], pk)
        print(f"     bound group '{name}'")

    print("=" * 64)
    print("zephyrus-oidc registered. Put these in /var/www/Zephyrus/.env:")
    print(f"OIDC_DISCOVERY_URL={args.base_url}/application/o/{APP_SLUG}/.well-known/openid-configuration")
    print(f"OIDC_CLIENT_ID={client_id}")
    print(f"OIDC_CLIENT_SECRET={client_secret}")
    print(f"OIDC_REDIRECT_URI={REDIRECT_URI}")
    print("=" * 64)
    return 0


if __name__ == "__main__":
    sys.exit(main())
