import pytest

from oauth_testlib import pkce_pair


@pytest.mark.rfc("RFC 7636", section="4.6")
def test_pkce_s256(oauth):
    verifier, challenge = pkce_pair()
    code = oauth.authorization_code(code_challenge=challenge, code_challenge_method="S256")
    response = oauth.exchange_code(code, verifier=verifier)
    assert response.status_code == 200, response.text
    assert response.json().get("access_token")


@pytest.mark.rfc("RFC 7636", section="4.6")
def test_pkce_wrong_verifier_is_rejected(oauth):
    _, challenge = pkce_pair()
    wrong_verifier, _ = pkce_pair()
    code = oauth.authorization_code(code_challenge=challenge, code_challenge_method="S256")
    response = oauth.exchange_code(code, verifier=wrong_verifier)
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_grant", "invalid_request"}


@pytest.mark.rfc("RFC 7636", section="4.6")
def test_pkce_missing_verifier_is_rejected(oauth):
    _, challenge = pkce_pair()
    code = oauth.authorization_code(code_challenge=challenge, code_challenge_method="S256")
    response = oauth.exchange_code(code)
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_grant", "invalid_request"}
