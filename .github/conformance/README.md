# OIDC Conformance Test Configuration

This directory contains configuration files and scripts for running OpenID Connect (OIDC) conformance tests against the Nextcloud OIDC Identity Provider app.

## Files

- **`oidc-basic-config.json`** - Conformance test plan configuration with test user credentials and client credentials
- **`write-config.py`** - Python script to generate final conformance config from template (replaces environment variables)
- **`docker-compose.github-actions.yml`** - Docker Compose overrides for GitHub Actions CI/CD environment
- **`docker-compose.chrome.yml`** - Docker Compose service definition for Selenium + Headless Chrome (alternative to HtmlUnit)
- **`browser-runner.py`** - External Selenium/Chromium browser worker for conformance front-channel URLs
- **`run-conformance-chrome.sh`** - Local development script to run conformance tests with Headless Chrome
- **`HEADLESS_CHROME_GUIDE.md`** - Detailed guide for setting up and running tests with Chrome instead of HtmlUnit

## Quick Start

### Local Development with Headless Chrome

```bash
# Make script executable
chmod +x run-conformance-chrome.sh

# Run tests (handles all setup)
./run-conformance-chrome.sh
```

The script will:
1. Verify Chrome and Chromedriver are installed
2. Start Chromedriver on port 9515
3. Start Nextcloud Docker containers (if not running)
4. Run the conformance test suite
5. Cleanup resources

### GitHub Actions CI/CD

The workflow defined in `.github/workflows/build-test.yaml` runs conformance tests when manually triggered with `run_conformance=true`:

```bash
# Via GitHub CLI
gh workflow run build-test.yaml -f run_conformance=true

# Or manually in GitHub Actions UI:
# Actions > Build app > Run workflow > run_conformance checkbox
```

## Browser Engine Selection

### HtmlUnit (Default - Not Recommended)
- ❌ Does not support modern ES6 JavaScript
- ❌ Causes `org.htmlunit.ScriptException: identifier is a reserved word: class` errors
- ✓ No additional setup needed

### External Headless Chrome + Selenium (Recommended)
- ✓ Full modern JavaScript support (ES6+)
- ✓ More accurate simulation of real browser behavior
- ✓ Better debugging with VNC access (port 7900)
- ⚠ Requires Chrome/Chromedriver installation

The conformance suite's built-in browser automation is HtmlUnit-based. To avoid
modifying the suite source, keep the test config free of `browser` commands and
run `browser-runner.py` alongside `scripts/run-test-plan.py`. The runner polls
the suite API for exposed front-channel URLs and drives them through Chromium.

## Environment Variables

When running conformance tests, these variables configure the external browser
runner and server URLs:

- `SELENIUM_REMOTE_URL` - WebDriver endpoint (e.g., `http://chrome:4444/wd/hub`)
- `CONFORMANCE_SERVER` - Nextcloud OIDC provider URL (e.g., `https://nginx:8443/`)
- `CONFORMANCE_SERVER_MTLS` - Mutual TLS endpoint (e.g., `https://nginx:8444/`)
- `CONFORMANCE_DEV_MODE` - Developer mode (`1` = enabled)

## Troubleshooting

### Chrome/Chromedriver Version Mismatch
```bash
# Check versions
google-chrome --version
chromedriver --version

# Install matching versions
brew install --cask google-chrome chromedriver
# or
brew upgrade google-chrome chromedriver
```

### Chromedriver Port Already in Use
```bash
# Find and kill process on port 9515
lsof -i :9515
kill -9 <PID>
```

### Docker Compose Networking Issues
Ensure all container names resolve correctly:
```bash
# Add to /etc/hosts (macOS/Linux)
sudo sh -c 'echo "127.0.0.1 nginx" >> /etc/hosts'
sudo sh -c 'echo "127.0.0.1 chrome" >> /etc/hosts'
```

### VNC Debugging (to watch Chrome execution)
```bash
# On local machine, access VNC viewer
# Address: localhost:7900 (or docker_host:7900 if remote)
# Password: secret (default)
```

## References

- [OpenID Connect Conformance Suite Documentation](https://gitlab.com/openid/conformance-suite)
- [OIDC Specification](https://openid.net/specs/openid-connect-core-1_0.html)
- [Selenium Docker Images](https://github.com/SeleniumHQ/docker-selenium)
- [Chromium WebDriver](https://chromedriver.chromium.org/)

## Related Configuration

- Test plan: `oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]`
- Test user credentials: `OIDC_TEST_USER` / `OIDC_TEST_PASSWORD` (set in workflow)
- OIDC clients: Two clients created dynamically in Nextcloud (`OIDC_CLIENT_ID_1/2`, `OIDC_CLIENT_SECRET_1/2`)
