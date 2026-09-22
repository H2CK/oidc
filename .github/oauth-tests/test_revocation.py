import pytest

from oauth_testlib import env_true


@pytest.mark.optional_extension("RFC 7009 Token Revocation")
@pytest.mark.rfc("RFC 7009", section="2.1")
def test_access_token_revocation_when_supported(oauth):
    endpoint = oauth.metadata.get("revocation_endpoint")
    if not endpoint:
        if env_true("OAUTH_REQUIRE_REVOCATION"):
            pytest.fail("revocation endpoint is required but not advertised")
        pytest.skip("RFC 7009 revocation is not implemented")

    access_token = oauth.issue_tokens("openid profile")["access_token"]
    response = oauth.http.post(endpoint, data={"token": access_token}, auth=(
        __import__("oauth_testlib").CLIENT_ID,
        __import__("oauth_testlib").CLIENT_SECRET,
    ))
    assert response.status_code == 200, response.text
    introspection = oauth.introspect(access_token)
    assert introspection.status_code == 200
    assert introspection.json().get("active") is False
