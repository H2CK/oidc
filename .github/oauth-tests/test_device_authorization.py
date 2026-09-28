import pytest
import time

from oauth_testlib import CLIENT_ID, CLIENT_SECRET, DEVICE_GRANT, PUBLIC_CLIENT_ID, env_true


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
@pytest.mark.rfc("RFC 8628", section="3.1")
def test_public_client_can_start_device_authorization(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    response = oauth.http.post(endpoint, data={
        "client_id": PUBLIC_CLIENT_ID,
        "scope": "openid profile",
    })
    assert response.status_code == 200, response.text
    assert response.json().get("device_code")


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.5")
def test_unknown_device_code_is_rejected(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    response = oauth.token({"grant_type": DEVICE_GRANT,
                            "device_code": "unknown-device-code-000000000000"})
    assert response.status_code == 400
    assert response.json().get("error") == "invalid_grant"


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
    data = start.json()
    interval = int(data.get("interval", 5))
    time.sleep(interval)
    response = oauth.token({"grant_type": DEVICE_GRANT, "device_code": data["device_code"]})
    assert response.status_code == 400
    assert response.json().get("error") == "authorization_pending"


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.5")
def test_device_code_polling_too_fast_returns_slow_down(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    start = oauth.http.post(endpoint, data={"scope": "openid profile"}, auth=(CLIENT_ID, CLIENT_SECRET))
    assert start.status_code == 200, start.text
    device_code = start.json()["device_code"]
    first = oauth.token({"grant_type": DEVICE_GRANT, "device_code": device_code})
    assert first.status_code == 400
    assert first.json().get("error") == "authorization_pending"
    second = oauth.token({"grant_type": DEVICE_GRANT, "device_code": device_code})
    assert second.status_code == 400
    assert second.json().get("error") == "slow_down"


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.5")
def test_approved_device_code_can_be_exchanged_once(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    start = oauth.http.post(endpoint, data={"scope": "openid profile"}, auth=(CLIENT_ID, CLIENT_SECRET))
    assert start.status_code == 200, start.text
    data = start.json()
    oauth.device_verification_action(data, "approve")

    response = oauth.token({"grant_type": DEVICE_GRANT, "device_code": data["device_code"]})
    assert response.status_code == 200, response.text
    assert response.json().get("access_token")

    replay = oauth.token({"grant_type": DEVICE_GRANT, "device_code": data["device_code"]})
    assert replay.status_code == 400
    assert replay.json().get("error") == "invalid_grant"


@pytest.mark.optional_extension("RFC 8628 Device Authorization")
@pytest.mark.rfc("RFC 8628", section="3.5")
def test_denied_device_code_returns_access_denied(oauth):
    endpoint = _device_endpoint(oauth)
    if not endpoint:
        if env_true("OAUTH_REQUIRE_DEVICE_AUTH"):
            pytest.fail("Device Authorization Grant is required but no endpoint is configured/advertised")
        pytest.skip("RFC 8628 Device Authorization is not implemented")

    start = oauth.http.post(endpoint, data={"scope": "openid profile"}, auth=(CLIENT_ID, CLIENT_SECRET))
    assert start.status_code == 200, start.text
    data = start.json()
    oauth.device_verification_action(data, "deny")

    response = oauth.token({"grant_type": DEVICE_GRANT, "device_code": data["device_code"]})
    assert response.status_code == 400
    assert response.json().get("error") == "access_denied"
