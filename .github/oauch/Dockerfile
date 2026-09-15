ARG OAUCH_IMAGE=pieterphilippaerts/oauch-image:latest
FROM ${OAUCH_IMAGE}
USER root
COPY nextcloud-test-ca.crt /usr/local/share/ca-certificates/nextcloud-test-ca.crt
RUN update-ca-certificates
