import pytest

from oauth_testlib import CLIENT_ID, CLIENT_SECRET, env_true


def _revocation_endpoint(oauth):
    endpoint = oauth.metadata.get("revocation_endpoint")
    if not endpoint:
        if env_true("OAUTH_REQUIRE_REVOCATION"):
            pytest.fail("revocation endpoint is required but not advertised")
        pytest.skip("RFC 7009 revocation is not implemented")
    return endpoint


@pytest.mark.optional_extension("RFC 7009 Token Revocation")
@pytest.mark.rfc("RFC 7009", section="2.1")
def test_access_token_revocation_when_supported(oauth):
    endpoint = _revocation_endpoint(oauth)

    access_token = oauth.issue_tokens("openid profile")["access_token"]
    response = oauth.http.post(endpoint, data={"token": access_token}, auth=(CLIENT_ID, CLIENT_SECRET))
    assert response.status_code == 200, response.text
    introspection = oauth.introspect(access_token)
    assert introspection.status_code == 200
    assert introspection.json().get("active") is False


@pytest.mark.optional_extension("RFC 7009 Token Revocation")
@pytest.mark.rfc("RFC 7009", section="2.2")
def test_unknown_token_revocation_is_idempotent(oauth):
    endpoint = _revocation_endpoint(oauth)
    response = oauth.http.post(endpoint,
                               data={"token": "unknown-revocation-token-000000000000"},
                               auth=(CLIENT_ID, CLIENT_SECRET))
    assert response.status_code == 200, response.text


@pytest.mark.optional_extension("RFC 7009 Token Revocation")
@pytest.mark.rfc("RFC 7009", section="2.1")
def test_revoking_refresh_token_prevents_future_refresh(oauth):
    endpoint = _revocation_endpoint(oauth)
    tokens = oauth.issue_tokens("openid profile offline_access")
    refresh_token = tokens.get("refresh_token")
    assert refresh_token

    response = oauth.http.post(endpoint, data={"token": refresh_token},
                               auth=(CLIENT_ID, CLIENT_SECRET))
    assert response.status_code == 200, response.text
    refreshed = oauth.token({"grant_type": "refresh_token", "refresh_token": refresh_token})
    assert refreshed.status_code == 400
    assert refreshed.json().get("error") == "invalid_grant"
