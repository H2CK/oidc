import secrets

import pytest

from oauth_testlib import CLIENT_ID


@pytest.mark.rfc("RFC 6749", section="4.1.1")
@pytest.mark.rfc("RFC 9700", section="2.1")
def test_unregistered_redirect_uri_is_not_used(oauth):
    attacker_uri = "https://oauth-callback:9444/other"
    # Redirect URI validation precedes login. A protocol test for this case
    # must not first run an unrelated interactive login/code exchange.
    response = oauth.http.get(oauth.metadata["authorization_endpoint"], params={
        "client_id": CLIENT_ID,
        "response_type": "code",
        "redirect_uri": attacker_uri,
        "scope": "openid",
        "state": secrets.token_urlsafe(20),
        "nonce": secrets.token_urlsafe(20),
    })

    assert response.status_code == 403
    assert not response.headers.get("location", "").startswith(attacker_uri)


@pytest.mark.rfc("RFC 9700", section="2.1.2")
def test_implicit_access_token_response_type_is_not_issued(oauth):
    final_url = oauth.authorization_attempt(response_type="token")

    assert "access_token" not in final_url.lower(), final_url
    # An OAuth error may be redirected to the registered callback. It must not
    # contain an access token; the provider currently reports this request as
    # unsupported because the implicit token response is disabled.
    assert "error=unsupported_response_type" in final_url.lower(), final_url
