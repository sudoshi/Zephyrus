# Zephyrus Patient-Flow Arena — OCPM sidecar (Part X, X1).
# Pinned to Python 3.12 (pm4py's numpy/pandas/scipy wheels resolve cleanly here).
FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /app

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Install deps first for layer caching (context is the repo root, like eddy/php).
COPY arena/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY arena/ /app/

EXPOSE 8100

# pm4py cold import is slow; give the container a generous start period.
HEALTHCHECK --interval=15s --timeout=3s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost:8100/health || exit 1

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100"]
