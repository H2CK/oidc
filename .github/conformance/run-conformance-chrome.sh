#!/bin/bash
# Local conformance test runner with Headless Chrome
# Usage: ./run-conformance-chrome.sh

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}OIDC Conformance Test Runner with Headless Chrome${NC}"
echo "======================================================"

# Check prerequisites
echo -e "\n${YELLOW}Checking prerequisites...${NC}"

if ! command -v google-chrome &> /dev/null && ! command -v chromium &> /dev/null; then
    echo -e "${RED}✗ Chrome/Chromium not found${NC}"
    echo "Install with: brew install --cask google-chrome"
    exit 1
fi
echo -e "${GREEN}✓ Chrome found${NC}"

if ! command -v chromedriver &> /dev/null; then
    echo -e "${RED}✗ Chromedriver not found${NC}"
    echo "Install with: brew install chromedriver"
    exit 1
fi

if ! python3 -c "import httpx, pyparsing, selenium" &> /dev/null; then
    echo -e "${RED}Python conformance dependencies not found${NC}"
    echo "Install with: python3 -m pip install --user httpx pyparsing selenium"
    exit 1
fi

# Verify Chrome and Chromedriver versions match (major version)
CHROME_VERSION=$(google-chrome --version | awk '{print $3}' | cut -d. -f1)
DRIVER_VERSION=$(chromedriver --version | awk '{print $2}' | cut -d. -f1)

echo -e "${GREEN}✓ Chromedriver found (Chrome v${CHROME_VERSION}, Driver v${DRIVER_VERSION})${NC}"

if [ "$CHROME_VERSION" != "$DRIVER_VERSION" ]; then
    echo -e "${YELLOW}⚠ Warning: Chrome major version ($CHROME_VERSION) differs from Chromedriver major version ($DRIVER_VERSION)${NC}"
    echo "This may cause compatibility issues. Consider updating one of them."
    read -p "Continue anyway? (y/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        exit 1
    fi
fi

# Start chromedriver
echo -e "\n${YELLOW}Starting Chromedriver on port 9515...${NC}"
chromedriver --port=9515 > /tmp/chromedriver.log 2>&1 &
CHROMEDRIVER_PID=$!
echo $CHROMEDRIVER_PID > /tmp/chromedriver.pid
echo -e "${GREEN}✓ Chromedriver started (PID: $CHROMEDRIVER_PID)${NC}"

# Wait for chromedriver to be ready
echo -e "\n${YELLOW}Waiting for Chromedriver to be ready...${NC}"
for i in {1..30}; do
    if curl -s http://localhost:9515/status | grep -q "ready"; then
        echo -e "${GREEN}✓ Chromedriver is ready${NC}"
        break
    fi
    if [ $i -eq 30 ]; then
        echo -e "${RED}✗ Chromedriver did not become ready in time${NC}"
        kill $CHROMEDRIVER_PID
        exit 1
    fi
    sleep 1
done

# Set environment variables
export CONFORMANCE_DEV_MODE=1
export CONFORMANCE_SERVER=https://nginx:8443/
export CONFORMANCE_SERVER_MTLS=https://nginx:8444/
export SELENIUM_REMOTE_URL=http://localhost:9515
export OIDC_TEST_USER=${OIDC_TEST_USER:-oidc-test-user}
export OIDC_TEST_PASSWORD=${OIDC_TEST_PASSWORD:-oidc-test-password}

echo -e "\n${YELLOW}Environment variables set:${NC}"
echo "  SELENIUM_REMOTE_URL=$SELENIUM_REMOTE_URL"
echo "  CONFORMANCE_SERVER=$CONFORMANCE_SERVER"

# Check if conformance-suite exists
if [ ! -d "./conformance-suite" ]; then
    echo -e "\n${YELLOW}Conformance suite not found. Cloning...${NC}"
    git clone --depth 1 https://gitlab.com/openid/conformance-suite.git conformance-suite
fi

# Build conformance suite
if [ ! -f "./conformance-suite/target/fintechlabs-conformance-suite-5.1.0-SNAPSHOT-jar-with-dependencies.jar" ]; then
    echo -e "\n${YELLOW}Building conformance suite...${NC}"
    cd conformance-suite
    mvn -B -DskipTests package -q
    cd ..
fi

# Check if Nextcloud containers are running
echo -e "\n${YELLOW}Checking Nextcloud Docker containers...${NC}"
if ! docker compose -f conformance-suite/docker-compose-localtest.yml ps | grep -q "nginx"; then
    echo -e "${YELLOW}Starting Nextcloud containers...${NC}"
    cd conformance-suite
    docker compose \
        -f docker-compose-localtest.yml \
        -f ../../.github/conformance/docker-compose.github-actions.yml \
        up -d mongodb nginx server

    # Wait for services
    echo -e "\n${YELLOW}Waiting for services to be ready...${NC}"
    for i in {1..60}; do
        if curl --insecure --fail --silent https://nginx:8443/api/runner/available > /dev/null 2>&1; then
            echo -e "${GREEN}✓ Services are ready${NC}"
            break
        fi
        if [ $i -eq 60 ]; then
            echo -e "${RED}✗ Services did not become ready in time${NC}"
            kill $(cat /tmp/chromedriver.pid)
            exit 1
        fi
        echo -n "."
        sleep 2
    done
    cd ..
fi

# Run conformance tests
echo -e "\n${YELLOW}Running conformance tests...${NC}"
echo "=================================================="
cd conformance-suite
python3 ../.github/conformance/browser-runner.py > ../conformance-browser.log 2>&1 &
BROWSER_RUNNER_PID=$!
set +e
python3 scripts/run-test-plan.py \
    --export-dir ../conformance-results \
    --verbose \
    "oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]" \
    ../conformance-config/oidc-basic-config.json

TEST_RESULT=$?
set -e
cd ..

# Cleanup
echo -e "\n${YELLOW}Cleaning up...${NC}"
if [ -f /tmp/chromedriver.pid ]; then
    kill $(cat /tmp/chromedriver.pid) 2>/dev/null || true
    rm /tmp/chromedriver.pid
fi
if [ -n "${BROWSER_RUNNER_PID:-}" ]; then
    kill "$BROWSER_RUNNER_PID" 2>/dev/null || true
fi

if [ $TEST_RESULT -eq 0 ]; then
    echo -e "\n${GREEN}✓ Conformance tests completed successfully${NC}"
    echo "Results available in: conformance-results/"
else
    echo -e "\n${RED}✗ Conformance tests failed${NC}"
    exit 1
fi
