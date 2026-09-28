import pytest

from oauth_testlib import ACCESS_TOKEN_TYPE, TOKEN_EXCHANGE_GRANT, TOKEN_EXCHANGE_RESOURCE, env_true


def _supported_or_required(oauth) -> bool:
    advertised = TOKEN_EXCHANGE_GRANT in oauth.metadata.get("grant_types_supported", [])
    return advertised or env_true("OAUTH_REQUIRE_TOKEN_EXCHANGE")


def _exchange(oauth, subject_token: str | None, subject_type: str = ACCESS_TOKEN_TYPE,
              *, resource: str | None = TOKEN_EXCHANGE_RESOURCE, scope: str | None = None,
              client_id: str | None = None, client_secret: str | None = None,
              extra: dict[str, str] | None = None):
    data = {
        "grant_type": TOKEN_EXCHANGE_GRANT,
        "subject_token_type": subject_type,
        "requested_token_type": ACCESS_TOKEN_TYPE,
    }
    if resource is not None:
        data["resource"] = resource
    if scope is not None:
        data["scope"] = scope
    if subject_token is not None:
        data["subject_token"] = subject_token
    data.update(extra or {})
    if client_id is not None:
        return oauth.token(data, client_id=client_id,
                           client_secret=client_secret or "")
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


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_rejects_unapproved_resource(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, resource="https://unapproved.example/api")
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_target"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_requires_an_effective_resource(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, resource=None)
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_target"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_cannot_escalate_subject_scope(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, scope="openid profile email")
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_scope"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_can_only_downscope_subject_token(oauth):
    subject = oauth.issue_tokens("openid profile email")["access_token"]
    response = _exchange(oauth, subject, scope="openid")
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 200, response.text
    token_data = response.json()
    assert token_data.get("access_token")
    assert token_data.get("scope") in (None, "openid")
    introspection = oauth.introspect(token_data["access_token"])
    assert introspection.status_code == 200
    assert introspection.json().get("active") is True
    assert introspection.json().get("scope") == "openid"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.1")
def test_token_exchange_rejects_unsupported_audience(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, extra={"audience": "https://backend.example/api"})
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_target"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.1")
def test_token_exchange_rejects_unsupported_actor_token(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, extra={
        "actor_token": "unsupported-actor-token",
        "actor_token_type": ACCESS_TOKEN_TYPE,
    })
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_request", "invalid_grant"}


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.1")
def test_token_exchange_rejects_duplicate_resource_parameters(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = oauth.http.post(
        oauth.metadata["token_endpoint"],
        data=[("grant_type", TOKEN_EXCHANGE_GRANT),
              ("subject_token", subject),
              ("subject_token_type", ACCESS_TOKEN_TYPE),
              ("requested_token_type", ACCESS_TOKEN_TYPE),
              ("resource", TOKEN_EXCHANGE_RESOURCE),
              ("resource", "https://other.example/api")],
        auth=(CLIENT_ID, CLIENT_SECRET),
    )
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_target"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_is_disabled_for_unconfigured_client(oauth):
    subject = oauth.issue_tokens("openid profile")["access_token"]
    response = _exchange(oauth, subject, client_id=SECOND_CLIENT_ID,
                         client_secret=SECOND_CLIENT_SECRET)
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code in (400, 401)
    assert response.json().get("error") in {"unauthorized_client", "invalid_client"}


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.2")
def test_token_exchange_rejects_unapproved_subject_client(oauth):
    subject = oauth.issue_tokens("openid profile", client_id=SECOND_CLIENT_ID,
                                 client_secret=SECOND_CLIENT_SECRET)["access_token"]
    response = _exchange(oauth, subject)
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_request"


@pytest.mark.optional_extension("RFC 8693 Token Exchange")
@pytest.mark.rfc("RFC 8693", section="2.2.1")
def test_exchanged_token_does_not_outlive_subject_token(oauth):
    subject = oauth.issue_tokens("openid profile")
    response = _exchange(oauth, subject["access_token"])
    if response.status_code == 400 and response.json().get("error") == "unsupported_grant_type" and not _supported_or_required(oauth):
        pytest.skip("RFC 8693 Token Exchange is not implemented")
    assert response.status_code == 200, response.text
    assert int(response.json()["expires_in"]) <= int(subject["expires_in"])
