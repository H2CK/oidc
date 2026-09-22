# Self-hosted OAuch CI integration

This workflow intentionally runs a local/self-hosted OAuch container. It does
not automate the public `oauch.io` service.

OAuch documents that its test process is not fully automated. Authentication,
authorization, and deliberately malformed authorization requests can stop a run
until the operator signals that no callback will occur. The included Selenium
runner automates the common Nextcloud login/authorization path and attempts to
advance visible stalled-test controls.

For that reason:

- the workflow is `schedule` + `workflow_dispatch` only by default;
- it should not initially be a required pull-request status check;
- HTML pages, screenshots, OAuch DB files and Docker logs are retained as
  artifacts for diagnosis;
- the workflow builds OAuch from a pinned commit of the official
  `DistriNet/OAuch` repository instead of consuming a mutable Docker image;
- the local Dockerfile also installs the ephemeral test CA so OAuch can reach
  the HTTPS Nextcloud proxy.

The OAuch client redirect URI is `https://oauch.io/Callback`. Docker network DNS
maps `oauch.io` to the self-hosted container for the Selenium browser.
