import pytest

from oauth_testlib import CLIENT_ID, CLIENT_SECRET, REDIRECT_URI, SECOND_CLIENT_ID, SECOND_CLIENT_SECRET


@pytest.mark.rfc("RFC 6749", section="5.2")
def test_unsupported_grant_type(oauth):
    response = oauth.token({"grant_type": "urn:example:unsupported"})
    assert response.status_code == 400
    assert response.json().get("error") == "unsupported_grant_type"


@pytest.mark.rfc("RFC 6749", section="5.2")
def test_invalid_client_is_rejected(oauth):
    response = oauth.token(
        {"grant_type": "authorization_code", "code": "not-a-code", "redirect_uri": REDIRECT_URI},
        client_secret="definitely-wrong-client-secret-000000000000",
    )
    assert response.status_code in (400, 401)
    assert response.json().get("error") == "invalid_client"


@pytest.mark.rfc("RFC 6749", section="5.2")
def test_unknown_authorization_code_is_invalid_grant(oauth):
    response = oauth.exchange_code("unknown-authorization-code-000000000000")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_grant"


@pytest.mark.rfc("RFC 6749", section="4.1.3")
def test_authorization_code_client_secret_basic(oauth):
    code = oauth.authorization_code()
    response = oauth.exchange_code(code, auth_method="basic")
    assert response.status_code == 200, response.text
    data = response.json()
    assert data.get("access_token")
    assert data.get("token_type", "").lower() == "bearer"


@pytest.mark.rfc("RFC 6749", section="2.3.1")
def test_authorization_code_client_secret_post(oauth):
    code = oauth.authorization_code()
    response = oauth.exchange_code(code, auth_method="post")
    assert response.status_code == 200, response.text
    assert response.json().get("access_token")


@pytest.mark.rfc("RFC 6749", section="5.1")
def test_successful_token_response_is_not_cacheable(oauth):
    code = oauth.authorization_code()
    response = oauth.exchange_code(code)
    assert response.status_code == 200, response.text
    assert "no-store" in response.headers.get("cache-control", "").lower()
    assert "no-cache" in response.headers.get("pragma", "").lower()


@pytest.mark.rfc("RFC 6749", section="4.1.2")
def test_authorization_code_is_single_use(oauth):
    code = oauth.authorization_code()
    first = oauth.exchange_code(code)
    assert first.status_code == 200, first.text
    second = oauth.exchange_code(code)
    assert second.status_code == 400
    assert second.json().get("error") == "invalid_grant"


@pytest.mark.rfc("RFC 6749", section="4.1.3")
def test_authorization_code_is_bound_to_client(oauth):
    code = oauth.authorization_code(client_id=CLIENT_ID)
    response = oauth.exchange_code(
        code,
        client_id=SECOND_CLIENT_ID,
        client_secret=SECOND_CLIENT_SECRET,
    )
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_grant", "invalid_client"}


@pytest.mark.rfc("RFC 6749", section="4.1.3")
def test_redirect_uri_mismatch_is_rejected(oauth):
    code = oauth.authorization_code()
    response = oauth.exchange_code(code, redirect_uri="https://oauth-callback:9444/other")
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_grant", "invalid_request"}
