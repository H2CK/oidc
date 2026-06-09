# Guide: Running OIDC Conformance Tests with Headless Chrome

## Problem
The default OIDC conformance test runner uses HtmlUnit (a Java-based headless browser) which doesn't support modern ES6 JavaScript syntax (e.g., `class` keyword) used in Nextcloud's core bundles. This causes JavaScript execution errors like:
```
org.htmlunit.ScriptException: identifier is a reserved word: class
```

## Solution: Use an External Selenium + Headless Chrome Worker

The conformance suite's built-in browser automation is implemented in Java with
HtmlUnit. The workflow does not try to switch that internal implementation.
Instead, `oidc-basic-config.json` omits `browser` commands, which makes the
suite expose front-channel URLs through its runner API. `browser-runner.py`
polls that API and drives the URLs with Selenium/Chromium.

### Option 1: GitHub Actions Workflow (CI/CD)

**Changes to `.github/workflows/build-test.yaml`:**

In the `oidc_conformance` job, modify the "Build and start OpenID conformance suite" step:

```yaml
      - name: Build and start OpenID conformance suite
        run: |
          sudo sh -c 'echo "127.0.0.1 nginx" >> /etc/hosts'
          sudo sh -c 'echo "127.0.0.1 oidcc-provider" >> /etc/hosts'
          sudo sh -c 'echo "127.0.0.1 chrome" >> /etc/hosts'
          cd conformance-suite
          mvn -B -DskipTests package
          docker compose \
            -f docker-compose-localtest.yml \
            -f ../nextcloud/apps/${{ env.APP_NAME }}/.github/conformance/docker-compose.github-actions.yml \
            -f ../nextcloud/apps/${{ env.APP_NAME }}/.github/conformance/docker-compose.chrome.yml \
            up -d mongodb nginx server chrome
          
          # Wait for Chrome/Selenium service to be ready
          for i in {1..60}; do
            if curl --insecure --fail --silent http://chrome:4444/status > /dev/null; then
              echo "Chrome/Selenium service is ready"
              break
            fi
            sleep 2
          done
          
          # Wait for API runner
          for i in {1..60}; do
            if curl --insecure --fail --silent https://nginx:8443/api/runner/available > /dev/null; then
              exit 0
            fi
            sleep 5
          done
          docker compose -f docker-compose-localtest.yml logs server nginx chrome
          exit 1
```

**Run the external browser worker with the test plan:**

```yaml
      - name: Run OIDC basic conformance plan
        env:
          CONFORMANCE_DEV_MODE: 1
          CONFORMANCE_SERVER: https://nginx:8443/
          CONFORMANCE_SERVER_MTLS: https://nginx:8444/
          SELENIUM_REMOTE_URL: http://chrome:4444/wd/hub
          CONFORMANCE_BROWSER_VISIT_TIMEOUT: 90
        run: |
          cd conformance-suite
          python3 ../nextcloud/apps/${{ env.APP_NAME }}/.github/conformance/browser-runner.py \
            > ../conformance-browser.log 2>&1 &
          browser_runner_pid=$!
          trap 'kill "${browser_runner_pid}" 2>/dev/null || true' EXIT
          python3 scripts/run-test-plan.py \
            --export-dir ../conformance-results \
            --verbose \
            "oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]" \
            ../conformance-config/oidc-basic-config.json
```

### Option 2: Local Development

**Prerequisites:**
```bash
# Install Chrome
brew install --cask google-chrome

# Install Chromedriver (must match Chrome version)
brew install chromedriver
# or
brew install --cask chromedriver

# Verify versions match
google-chrome --version
chromedriver --version
```

**Start Chromedriver:**
```bash
chromedriver --port=9515 &
echo $! > chromedriver.pid
```

**Run tests locally:**
```bash
cd conformance-suite
mvn -B -DskipTests package

# Start containers (without Chrome, we'll use local Chromedriver)
docker compose \
  -f docker-compose-localtest.yml \
  -f ../nextcloud/apps/oidc/.github/conformance/docker-compose.github-actions.yml \
  up -d mongodb nginx server

# Wait for services
sleep 30

# Run the browser worker with local Chromedriver
python3 ../.github/conformance/browser-runner.py > ../conformance-browser.log 2>&1 &
browser_runner_pid=$!
trap 'kill "${browser_runner_pid}" 2>/dev/null || true' EXIT

python3 scripts/run-test-plan.py \
  --export-dir ../conformance-results \
  --verbose \
  "oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]" \
  ../conformance-config/oidc-basic-config.json
```

Set environment variables:
```bash
export CONFORMANCE_DEV_MODE=1
export CONFORMANCE_SERVER=https://nginx:8443/
export CONFORMANCE_SERVER_MTLS=https://nginx:8444/
export SELENIUM_REMOTE_URL=http://localhost:9515
```

### Option 3: Alternative - ES5 Transpilation (Without Browser Engine Change)

If you want to keep HtmlUnit but fix the ES6 compatibility issue, ensure Nextcloud's JavaScript is built as ES5:

Check `/nextcloud/webpack.config.js` or build configuration for:
```javascript
target: 'es5'  // or targets: ['es2015'] as minimum
```

Then rebuild:
```bash
cd nextcloud
npm ci
npm run build  // Ensure target is ES5 compatible
```

## Validation

After running with Chrome:
1. Check test logs for WebDriver calls instead of HtmlUnit errors
2. Expected log entries should show Chrome/Chromium processes executing JavaScript
3. No `org.htmlunit.ScriptException` errors
4. JavaScript should execute properly on login pages

## Files Modified

- `.github/workflows/build-test.yaml` - Starts the external browser worker with the test plan
- `.github/conformance/browser-runner.py` - Polls conformance front-channel URLs and drives Chromium
- `.github/conformance/oidc-basic-config.json` - Omits HtmlUnit browser commands
- `.github/conformance/docker-compose.chrome.yml` - New Selenium/Chrome service definition

## References

- [OpenID Conformance Suite](https://gitlab.com/openid/conformance-suite)
- [Selenium Docker Images](https://github.com/SeleniumHQ/docker-selenium)
- [Chromium WebDriver Protocol](https://chromedriver.chromium.org/)
