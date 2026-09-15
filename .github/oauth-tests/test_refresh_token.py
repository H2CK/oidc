import pytest

from oauth_testlib import SECOND_CLIENT_ID, SECOND_CLIENT_SECRET


@pytest.mark.rfc("OpenID Connect Core 1.0", section="11")
def test_refresh_token_requires_offline_access(oauth):
    tokens = oauth.issue_tokens("openid profile")
    assert "refresh_token" not in tokens


@pytest.mark.rfc("RFC 6749", section="6")
def test_refresh_token_flow(oauth):
    tokens = oauth.issue_tokens("openid profile offline_access")
    refresh_token = tokens.get("refresh_token")
    assert refresh_token
    response = oauth.token({"grant_type": "refresh_token", "refresh_token": refresh_token})
    assert response.status_code == 200, response.text
    assert response.json().get("access_token")


@pytest.mark.rfc("RFC 6749", section="6")
def test_refresh_token_is_bound_to_client(oauth):
    tokens = oauth.issue_tokens("openid profile offline_access")
    response = oauth.token(
        {"grant_type": "refresh_token", "refresh_token": tokens["refresh_token"]},
        client_id=SECOND_CLIENT_ID,
        client_secret=SECOND_CLIENT_SECRET,
    )
    assert response.status_code in (400, 401)
    assert response.json().get("error") in {"invalid_grant", "invalid_client"}


@pytest.mark.rfc("RFC 6749", section="6")
def test_refresh_cannot_escalate_scope(oauth):
    tokens = oauth.issue_tokens("openid profile offline_access")
    response = oauth.token(
        {
            "grant_type": "refresh_token",
            "refresh_token": tokens["refresh_token"],
            "scope": "openid profile email offline_access",
        }
    )
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_scope", "invalid_grant"}


@pytest.mark.rfc("RFC 6749", section="6")
def test_unknown_refresh_token_is_rejected(oauth):
    response = oauth.token({"grant_type": "refresh_token", "refresh_token": "unknown-refresh-token-000000000000"})
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_grant"


@pytest.mark.advisory
@pytest.mark.rfc("RFC 9700", section="4.14.2")
def test_refresh_token_rotation_rejects_replay_when_rotation_is_used(oauth):
    tokens = oauth.issue_tokens("openid profile offline_access")
    old_refresh = tokens["refresh_token"]
    first = oauth.token({"grant_type": "refresh_token", "refresh_token": old_refresh})
    assert first.status_code == 200, first.text
    new_refresh = first.json().get("refresh_token")
    if not new_refresh or new_refresh == old_refresh:
        pytest.skip("provider does not use refresh-token rotation for this confidential client")
    replay = oauth.token({"grant_type": "refresh_token", "refresh_token": old_refresh})
    assert replay.status_code == 400
    assert replay.json().get("error") == "invalid_grant"
