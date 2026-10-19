import pytest

from oauth_testlib import CONSENT_CLIENT_ID


@pytest.mark.rfc("OpenID Connect Core", section="3.1.2.6")
def test_consent_is_required_and_can_be_granted(oauth):
    without_interaction = oauth.authorize(
        client_id=CONSENT_CLIENT_ID,
        extra={"prompt": "none"},
    )
    assert without_interaction.get("error") == "consent_required", without_interaction

    granted = oauth.authorize(client_id=CONSENT_CLIENT_ID, require_consent=True)
    assert granted.get("code"), granted

    with_existing_consent = oauth.authorize(
        client_id=CONSENT_CLIENT_ID,
        extra={"prompt": "none"},
    )
    assert with_existing_consent.get("code"), with_existing_consent
