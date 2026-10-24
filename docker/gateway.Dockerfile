# CiegoLink Infrastructure Hub - SSH gateway
FROM python:3.12-slim-bookworm
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 \
    GATEWAY_HOST=0.0.0.0 GATEWAY_PORT=8022 \
    GATEWAY_KNOWN_HOSTS=/state/known_hosts.json HOME=/state
COPY ssh-gateway/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt \
 && useradd --system --uid 10001 --home-dir /state --shell /usr/sbin/nologin gateway \
 && install -d -m 700 -o gateway -g gateway /state
COPY ssh-gateway/gateway.py /app/gateway.py
USER gateway
VOLUME ["/state"]
EXPOSE 8022
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD python -c "import urllib.request;urllib.request.urlopen('http://127.0.0.1:8022/health',timeout=3)" || exit 1
CMD ["python", "/app/gateway.py"]
