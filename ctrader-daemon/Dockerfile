FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .
# Deux passes obligatoires : voir le commentaire de requirements.txt.
RUN pip install --no-cache-dir -r requirements.txt \
    && pip install --no-cache-dir -U pyOpenSSL service_identity requests tzdata

COPY *.py .

ENV CTRADER_TOKENS_FILE=/data/tokens.json \
    HEALTH_FILE=/tmp/ctrader-daemon-health

# Sain si un message (heartbeat serveur compris) a été reçu dans les 2 dernières minutes.
HEALTHCHECK --interval=60s --timeout=5s --start-period=30s \
    CMD python -c "import os,time,sys; sys.exit(0 if time.time() - os.path.getmtime(os.environ['HEALTH_FILE']) < 120 else 1)"

CMD ["python", "-u", "daemon.py"]
