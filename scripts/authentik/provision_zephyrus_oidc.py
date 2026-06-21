#!/usr/bin/env python3
"""Provision a zephyrus-oidc OAuth2/OpenID app in Authentik (Acropolis).

Idempotent: creates (or reuses) the OAuth2 provider, the `zephyrus-oidc`
application, and the `Zephyrus Users` / `Zephyrus Admins` groups, then binds the
groups to the application as an access policy. Prints client_id + client_secret.

Run on beastmode (Authentik reachable at https://auth.acumenus.net):
    AUTHENTIK_TOKEN=$(docker exec acropolis-authentik-server printenv AUTHENTIK_BOOTSTRAP_TOKEN) \
        python3 scripts/authentik/provision_zephyrus_oidc.py
"""
import os
import sys
import requests

BASE = "https://auth.acumenus.net/api/v3"
REDIRECT = "https://zephyrus.acumenus.net/auth/oidc/callback"
APP_SLUG = "zephyrus-oidc"
APP_NAME = "Zephyrus (OIDC)"
GROUPS = ["Zephyrus Users", "Zephyrus Admins"]

token = os.environ.get("AUTHENTIK_TOKEN")
if not token:
    sys.exit("Set AUTHENTIK_TOKEN (docker exec acropolis-authentik-server printenv AUTHENTIK_BOOTSTRAP_TOKEN)")

s = requests.Session()
s.headers.update({"Authorization": f"Bearer {token}", "Content-Type": "application/json"})


def get_list(path, **params):
    r = s.get(f"{BASE}{path}", params=params, timeout=15)
    r.raise_for_status()
    return r.json()["results"]


def ensure_group(name):
    existing = get_list("/core/groups/", search=name)
    for g in existing:
        if g["name"] == name:
            return g["pk"]
    r = s.post(f"{BASE}/core/groups/", json={"name": name})
    r.raise_for_status()
    return r.json()["pk"]


def default_flows():
    auth = get_list("/flow/instances/", slug="default-provider-authorization-implicit-consent")
    return auth[0]["pk"] if auth else None


def signing_key():
    keys = get_list("/crypto/certificatekeypairs/", search="authentik")
    return keys[0]["pk"] if keys else None


def ensure_provider():
    for p in get_list("/providers/oauth2/", search=APP_SLUG):
        if p["name"] == APP_SLUG:
            return p
    payload = {
        "name": APP_SLUG,
        "authorization_flow": default_flows(),
        "client_type": "confidential",
        "redirect_uris": REDIRECT,
        "sub_mode": "user_email",
        "include_claims_in_id_token": True,
        "signing_key": signing_key(),
        "property_mappings": [
            pm["pk"] for pm in get_list("/propertymappings/provider/scope/")
            if pm["scope_name"] in ("openid", "email", "profile", "groups")
        ],
    }
    r = s.post(f"{BASE}/providers/oauth2/", json=payload)
    r.raise_for_status()
    return r.json()


def ensure_application(provider_pk):
    for a in get_list("/core/applications/", search=APP_SLUG):
        if a["slug"] == APP_SLUG:
            return a
    r = s.post(f"{BASE}/core/applications/", json={
        "name": APP_NAME, "slug": APP_SLUG, "provider": provider_pk,
        "meta_launch_url": "https://zephyrus.acumenus.net/",
    })
    r.raise_for_status()
    return r.json()


def bind_group(app_pk, group_pk):
    existing = get_list("/policies/bindings/", target=app_pk)
    if any(b.get("group") == group_pk for b in existing):
        return
    s.post(f"{BASE}/policies/bindings/", json={
        "target": app_pk, "group": group_pk, "order": 0, "enabled": True,
    }).raise_for_status()


def main():
    group_pks = {name: ensure_group(name) for name in GROUPS}
    provider = ensure_provider()
    app = ensure_application(provider["pk"])
    for name in GROUPS:
        bind_group(app["pk"], group_pks[name])

    print("=== zephyrus-oidc provisioned ===")
    print(f"discovery_url : https://auth.acumenus.net/application/o/{APP_SLUG}/.well-known/openid-configuration")
    print(f"redirect_uri  : {REDIRECT}")
    print(f"OIDC_CLIENT_ID={provider['client_id']}")
    print(f"OIDC_CLIENT_SECRET={provider['client_secret']}")
    print("Groups:", group_pks)
    print("Put intended users in the 'Zephyrus Users' (or 'Zephyrus Admins') group.")


if __name__ == "__main__":
    main()
