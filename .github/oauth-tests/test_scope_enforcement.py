import pytest

from oauth_testlib import SCOPE_LIMITED_CLIENT_ID, SCOPE_LIMITED_CLIENT_SECRET


@pytest.mark.rfc("RFC 6749", section="3.3")
@pytest.mark.rfc("RFC 6749", section="5.1")
def test_authorization_never_issues_scopes_outside_client_allowlist(oauth):
    tokens = oauth.issue_tokens("openid profile email", client_id=SCOPE_LIMITED_CLIENT_ID,
                                client_secret=SCOPE_LIMITED_CLIENT_SECRET)
    assert tokens.get("scope") == "openid profile"

    introspection = oauth.introspect(tokens["access_token"])
    assert introspection.status_code == 200, introspection.text
    assert introspection.json().get("active") is True
    assert introspection.json().get("scope") == "openid profile"
