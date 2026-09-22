import pytest


@pytest.mark.rfc("RFC 7662", section="2.2")
def test_active_access_token_introspection(oauth):
    token = oauth.issue_tokens("openid profile")["access_token"]
    response = oauth.introspect(token)
    assert response.status_code == 200, response.text
    data = response.json()
    assert data.get("active") is True
    assert data.get("client_id") or data.get("sub")


@pytest.mark.rfc("RFC 7662", section="2.2")
def test_unknown_token_is_inactive(oauth):
    response = oauth.introspect("this-token-does-not-exist-000000000000")
    assert response.status_code == 200, response.text
    assert response.json().get("active") is False


@pytest.mark.rfc("RFC 7662", section="2.1")
def test_introspection_requires_valid_client_authentication(oauth):
    endpoint = oauth.metadata.get("introspection_endpoint")
    if not endpoint:
        endpoint = oauth.metadata["issuer"].rstrip("/") + "/index.php/apps/oidc/introspect"
    response = oauth.http.post(endpoint, data={"token": "irrelevant"}, auth=("invalid-client", "invalid-secret"))
    assert response.status_code in (400, 401, 403)
