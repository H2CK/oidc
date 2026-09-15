from urllib.parse import urlsplit

import pytest


@pytest.mark.rfc("RFC 8414", section="2")
def test_metadata_has_core_endpoints(oauth):
    md = oauth.metadata
    for key in ("issuer", "authorization_endpoint", "token_endpoint", "jwks_uri"):
        assert md.get(key), f"missing {key}"
        assert urlsplit(md[key]).scheme == "https", f"{key} must use https"
    assert "code" in md.get("response_types_supported", [])


@pytest.mark.rfc("RFC 7636", section="4.2")
def test_pkce_metadata_does_not_advertise_weak_only_support(oauth):
    methods = oauth.metadata.get("code_challenge_methods_supported")
    if methods is not None:
        assert "S256" in methods
