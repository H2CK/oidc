import pytest

from oauth_testlib import env_true


def _registration_endpoint(oauth):
    endpoint = oauth.metadata.get("registration_endpoint")
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DCR"):
            pytest.fail("Dynamic Client Registration is required but no endpoint is advertised")
        pytest.skip("Dynamic Client Registration is not implemented")
    return endpoint


@pytest.mark.rfc("RFC 7591", section="3.2.1")
def test_dynamic_registration_and_rfc7592_management(oauth):
    endpoint = _registration_endpoint(oauth)

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

    unauthenticated_update = oauth.http.put(data["registration_client_uri"], json=payload)
    assert unauthenticated_update.status_code in (400, 401, 403)

    updated_payload = {**payload, "client_id": data["client_id"],
                       "client_name": "OAuth conformance updated client"}
    updated = oauth.http.put(data["registration_client_uri"], json=updated_payload, headers=headers)
    assert updated.status_code == 200, updated.text
    assert updated.json().get("client_name") == updated_payload["client_name"]

    # The client returned by registration must work at the OAuth endpoints,
    # not just round-trip through its management endpoint.
    code = oauth.authorization_code(client_id=data["client_id"])
    token = oauth.exchange_code(code, client_id=data["client_id"],
                                client_secret=data["client_secret"])
    assert token.status_code == 200, token.text
    assert token.json().get("access_token")

    deleted = oauth.http.delete(data["registration_client_uri"], headers=headers)
    assert deleted.status_code in (200, 204), deleted.text
    after_delete = oauth.http.get(data["registration_client_uri"], headers=headers)
    assert after_delete.status_code != 200, after_delete.text


@pytest.mark.rfc("RFC 7591", section="3.2.2")
def test_dynamic_registration_rejects_unsupported_signing_algorithm(oauth):
    endpoint = _registration_endpoint(oauth)
    response = oauth.http.post(endpoint, json={
        "client_name": "OAuth conformance invalid algorithm client",
        "redirect_uris": ["https://oauth-callback:9444/callback"],
        "grant_types": ["authorization_code"],
        "response_types": ["code"],
        "token_endpoint_auth_method": "client_secret_basic",
        "id_token_signed_response_alg": "HS512",
    })
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_client_metadata"
