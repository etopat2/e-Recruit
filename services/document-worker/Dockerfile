FROM python:3.12.11-slim-bookworm AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1

RUN apt-get update && apt-get install -y --no-install-recommends \
    libglib2.0-0 libgl1 tesseract-ocr \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.runtime.txt ./
RUN pip install --no-cache-dir -r requirements.runtime.txt

FROM base AS development
COPY requirements.lock ./
RUN pip install --no-cache-dir -r requirements.lock
COPY . .
EXPOSE 8001
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--reload"]

FROM base AS production
RUN useradd --create-home --uid 10001 worker
COPY --chown=worker:worker . .
USER worker
EXPOSE 8001
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001", "--workers", "2"]
