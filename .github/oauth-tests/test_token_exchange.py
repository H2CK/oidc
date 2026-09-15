import pytest

from oauth_testlib import ACCESS_TOKEN_TYPE, TOKEN_EXCHANGE_GRANT, env_true


def _supported_or_required(oauth) -> bool:
    advertised = TOKEN_EXCHANGE_GRANT in oauth.metadata.get("grant_types_supported", [])
    return advertised or env_true("OAUTH_REQUIRE_TOKEN_EXCHANGE")


def _exchange(oauth, subject_token: str | None, subject_type: str = ACCESS_TOKEN_TYPE):
    data = {
        "grant_type": TOKEN_EXCHANGE_GRANT,
        "subject_token_type": subject_type,
        "requested_token_type": ACCESS_TOKEN_TYPE,
    }
    if subject_token is not None:
        data["subject_token"] = subject_token
    return oauth.token(data)


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.1")
def test_token_exchange_access_token_to_access_token(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject)
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type":
        if _supported_or_required(oauth):
            pytest.fail("Token Exchange is required/advertised but unsupported")
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 200, response.text
    data = response.json()
    assert data.get("access_token")
    assert data.get("token_type", "").lower() == "bearer"
    assert data.get("issued_token_type") == ACCESS_TOKEN_TYPE

    introspection = oauth.introspect(data["access_token"])
    assert introspection.status_code == 200, introspection.text
    assert introspection.json().get("active") is True


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_invalid_subject_token(oauth):
    response = _exchange(oauth, "invalid-subject-token-000000000000")
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_grant", "invalid_request"}


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.1")
def test_token_exchange_missing_subject_token(oauth):
    response = _exchange(oauth, None)
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_request"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.1")
def test_token_exchange_unsupported_subject_token_type(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, "urn:example:unsupported-token-type")
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_request", "invalid_grant"}
