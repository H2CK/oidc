import base64
import json

import jwt
import pytest

from oauth_testlib import JWT_CLIENT_ID, JWT_CLIENT_SECRET


def _decode_segment(segment: str) -> dict:
    padded = segment + "=" * (-len(segment) % 4)
    return json.loads(base64.urlsafe_b64decode(padded.encode("ascii")))


def _signing_key(oauth, header: dict):
    response = oauth.http.get(oauth.metadata["jwks_uri"])
    assert response.status_code == 200, response.text
    keys = response.json().get("keys", [])
    jwk = next((item for item in keys if item.get("kid") == header.get("kid")), None)
    assert jwk is not None, f"No JWKS key found for kid={header.get('kid')!r}"
    return jwt.PyJWK.from_dict(jwk).key


@pytest.mark.rfc("RFC 9068", section="2")
def test_jwt_access_token_matches_rfc9068_profile(oauth):
    tokens = oauth.issue_tokens("openid profile", client_id=JWT_CLIENT_ID,
                                client_secret=JWT_CLIENT_SECRET)
    access_token = tokens["access_token"]
    parts = access_token.split(".")
    assert len(parts) == 3
    header = _decode_segment(parts[0])
    assert header.get("typ", "").lower() == "at+jwt"
    assert header.get("alg") == "RS256"

    key = _signing_key(oauth, header)
    claims = jwt.decode(
        access_token,
        key=key,
        algorithms=["RS256"],
        issuer=oauth.metadata["issuer"],
        audience=JWT_CLIENT_ID,
        options={"require": ["iss", "sub", "aud", "exp", "iat", "jti", "client_id"]},
    )
    assert claims["client_id"] == JWT_CLIENT_ID
    assert claims["scope"] == "openid profile"

    # Introspection is allowed to the token-owning client or its audience.
    # Use the JWT client's credentials rather than the harness default client.
    introspection = oauth.introspect(access_token, client_id=JWT_CLIENT_ID,
                                     client_secret=JWT_CLIENT_SECRET)
    assert introspection.status_code == 200, introspection.text
    data = introspection.json()
    assert data.get("active") is True
    assert data.get("client_id") == JWT_CLIENT_ID
    assert data.get("scope") == "openid profile"


@pytest.mark.rfc("RFC 9068", section="2")
def test_jwt_access_token_rejects_tampered_signature(oauth):
    access_token = oauth.issue_tokens("openid profile", client_id=JWT_CLIENT_ID,
                                      client_secret=JWT_CLIENT_SECRET)["access_token"]
    header = _decode_segment(access_token.split(".")[0])
    key = _signing_key(oauth, header)
    parts = access_token.split(".")
    signature = bytearray(base64.urlsafe_b64decode(parts[2] + "=" * (-len(parts[2]) % 4)))
    signature[0] ^= 0x01
    parts[2] = base64.urlsafe_b64encode(signature).rstrip(b"=").decode("ascii")

    tampered_token = ".".join(parts)
    with pytest.raises(jwt.InvalidSignatureError):
        jwt.decode(tampered_token, key=key, algorithms=["RS256"],
                   issuer=oauth.metadata["issuer"], audience=JWT_CLIENT_ID)

    introspection = oauth.introspect(tampered_token, client_id=JWT_CLIENT_ID,
                                     client_secret=JWT_CLIENT_SECRET)
    assert introspection.status_code == 200, introspection.text
    assert introspection.json().get("active") is False
