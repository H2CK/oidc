import pytest


@pytest.mark.rfc("RFC 7591", section="3.2.1")
def test_dynamic_registration_and_rfc7592_management(oauth):
    endpoint = oauth.metadata.get("registration_endpoint")
    if not endpoint:
        pytest.skip("registration_endpoint is not advertised")

    payload = {
        "client_name": "OAuth conformance dynamic client",
        "redirect_uris": ["https://oauth-callback:9444/callback"],
        "grant_types": ["authorization_code", "refresh_token"],
        "response_types": ["code"],
        "token_endpoint_auth_method": "client_secret_basic",
        "scope": "openid profile offline_access",
    }
    created = oauth.http.post(endpoint, json=payload)
    assert created.status_code in (200, 201), created.text
    data = created.json()
    assert data.get("client_id")
    assert data.get("client_secret")
    assert data.get("registration_access_token")
    assert data.get("registration_client_uri")

    headers = {"Authorization": f"Bearer {data['registration_access_token']}"}
    fetched = oauth.http.get(data["registration_client_uri"], headers=headers)
    assert fetched.status_code == 200, fetched.text
    assert fetched.json().get("client_id") == data["client_id"]

    unauthenticated = oauth.http.get(data["registration_client_uri"])
    assert unauthenticated.status_code in (400, 401, 403)

    deleted = oauth.http.delete(data["registration_client_uri"], headers=headers)
    assert deleted.status_code in (200, 204), deleted.text
