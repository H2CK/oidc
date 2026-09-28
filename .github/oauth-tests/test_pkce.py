import pytest

from oauth_testlib import PUBLIC_CLIENT_ID, pkce_pair


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


@pytest.mark.rfc("RFC 7636", section="4.6")
def test_public_client_uses_pkce_without_a_secret(oauth):
    verifier, challenge = pkce_pair()
    code = oauth.authorization_code(client_id=PUBLIC_CLIENT_ID,
                                    code_challenge=challenge,
                                    code_challenge_method="S256")
    response = oauth.exchange_code(code, client_id=PUBLIC_CLIENT_ID,
                                   client_secret="", verifier=verifier,
                                   auth_method="none")
    assert response.status_code == 200, response.text
    assert response.json().get("access_token")


@pytest.mark.rfc("RFC 9700", section="2.1.1")
def test_public_client_authorization_requires_pkce_s256(oauth):
    final_url = oauth.authorization_attempt(client_id=PUBLIC_CLIENT_ID)
    assert "error=invalid_request" in final_url.lower(), final_url
    assert "code=" not in final_url.lower(), final_url


@pytest.mark.rfc("RFC 9700", section="4.8.2")
def test_pkce_downgrade_verifier_without_challenge_is_rejected(oauth):
    verifier, _ = pkce_pair()
    code = oauth.authorization_code()
    response = oauth.exchange_code(code, verifier=verifier)
    assert response.status_code == 400
    assert response.json().get("error") in {"invalid_grant", "invalid_request"}
