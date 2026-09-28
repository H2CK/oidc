import json

import pytest

from oauth_testlib import CALLBACK_FILE


@pytest.mark.rfc("RFC 6749", section="4.1.1")
@pytest.mark.rfc("RFC 9700", section="2.1")
def test_unregistered_redirect_uri_is_not_used(oauth):
    attacker_uri = "https://oauth-callback:9444/other"
    final_url = oauth.authorization_attempt(redirect_uri=attacker_uri)

    assert not final_url.startswith(attacker_uri), final_url
    if CALLBACK_FILE.exists():
        callback = json.loads(CALLBACK_FILE.read_text(encoding="utf-8"))
        assert callback.get("path") != "/other", callback


@pytest.mark.rfc("RFC 9700", section="2.1.2")
def test_implicit_access_token_response_type_is_not_issued(oauth):
    final_url = oauth.authorization_attempt(response_type="token")

    assert "access_token" not in final_url.lower(), final_url
    # An OAuth error may be redirected to the registered callback. It must not
    # contain an access token; the provider currently reports this request as
    # unsupported because the implicit token response is disabled.
    assert "error=request_not_supported" in final_url.lower(), final_url
