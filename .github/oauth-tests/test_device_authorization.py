import pytest

from oauth_testlib import CLIENT_ID, CLIENT_SECRET, DEVICE_GRANT, env_true


def _device_endpoint(oauth):
    return (
        oauth.metadata.get("device_authorization_endpoint")
        or __import__("os").environ.get("OAUTH_DEVICE_AUTH_ENDPOINT")
    )


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.2")
def test_device_authorization_response(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    response = oauth.http.post(endpoint, data={"scope": "openid profile"}, auth=(CLIENT_ID, CLIENT_SECRET))
    assert response.status_code == 200, response.text
    data = response.json()
    for key in ("device_code", "user_code", "verification_uri", "expires_in"):
        assert data.get(key), f"missing {key}"


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.2")
def test_device_authorization_client_secret_post(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    response = oauth.http.post(
        endpoint,
        data={
            "client_id": CLIENT_ID,
            "client_secret": CLIENT_SECRET,
            "scope": "openid profile",
        },
    )
    assert response.status_code == 200, response.text
    assert response.json().get("device_code")


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.5")
def test_device_code_poll_before_authorization_is_pending(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    start = oauth.http.post(endpoint, data={"scope": "openid profile"}, auth=(CLIENT_ID, CLIENT_SECRET))
    assert start.status_code == 200, start.text
    response = oauth.token({"grant_type": DEVICE_GRANT, "device_code": start.json()["device_code"]})
    assert response.status_code == 400
    assert response.json().get("error") in {"authorization_pending", "slow_down"}
