#ddev-generated
# dimag-sim: KM-SOAP of the DIMAG core module, simulated.
FROM php:8.3-cli-alpine

RUN mkdir -p /app /data/sftp/Import /data/state \
 && chown -R 1000:1000 /data

COPY src /app/src
COPY public /app/public

ENV DIMAG_SIM_SFTP_DIR=/data/sftp/Import \
    DIMAG_SIM_STATE=/data/state/state.json \
    PHP_CLI_SERVER_WORKERS=4

USER 1000:1000
WORKDIR /app
EXPOSE 8080
HEALTHCHECK --interval=5s --timeout=3s --retries=10 \
  CMD wget -q -O - http://127.0.0.1:8080/_sim/health >/dev/null || exit 1
CMD ["php", "-d", "variables_order=EGPCS", "-S", "0.0.0.0:8080", "public/index.php"]
