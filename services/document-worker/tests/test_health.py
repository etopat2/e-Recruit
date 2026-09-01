import os

os.environ.setdefault("DOCUMENT_WORKER_TOKEN", "synthetic-worker-token")

from fastapi.testclient import TestClient

from app.main import app


def test_live_health_endpoint_returns_service_status() -> None:
    response = TestClient(app).get("/health/live")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "service": "document-worker"}


def test_processing_endpoint_rejects_missing_internal_token() -> None:
    response = TestClient(app).post(
        "/v1/process",
        data={"job_id": "synthetic-job", "expected_type": "national_id"},
        files={"document": ("synthetic.png", b"not-an-image", "image/png")},
    )

    assert response.status_code == 401


def test_internal_contract_exposes_typed_processing_boundary() -> None:
    contract = app.openapi()

    assert contract["info"]["version"] == "1.0.0"
    assert contract["paths"]["/v1/process"]["post"]["responses"]["200"][
        "content"
    ]["application/json"]["schema"]["$ref"].endswith("/ProcessingResult")
    assert "ProcessingResult" in contract["components"]["schemas"]
