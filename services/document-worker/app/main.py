import secrets
from typing import Annotated

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile, status

from .processor import DocumentProcessor, InvalidDocumentError
from .schemas import ProcessingResult
from .settings import Settings, get_settings

app = FastAPI(
    title="UPS e-Recruit Document Worker",
    version="1.0.0",
    docs_url=None,
    redoc_url=None,
    openapi_url=None,
)


def authorize_worker(
    settings: Annotated[Settings, Depends(get_settings)],
    x_worker_token: Annotated[str, Header()] = "",
) -> None:
    if not secrets.compare_digest(x_worker_token, settings.document_worker_token):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid worker token.",
        )


@app.get("/health/live")
def live() -> dict[str, str]:
    return {"status": "ok", "service": "document-worker"}


@app.get("/health/ready")
def ready(settings: Annotated[Settings, Depends(get_settings)]) -> dict[str, str]:
    return {"status": "ready", "engine": settings.ocr_engine}


@app.post(
    "/v1/process",
    response_model=ProcessingResult,
    dependencies=[Depends(authorize_worker)],
)
async def process_document(
    job_id: Annotated[str, Form(min_length=8, max_length=100)],
    expected_type: Annotated[str, Form(min_length=2, max_length=80)],
    document: Annotated[UploadFile, File()],
    settings: Annotated[Settings, Depends(get_settings)],
) -> ProcessingResult:
    content = await document.read(settings.max_document_bytes + 1)
    try:
        return DocumentProcessor(settings).process(
            job_id=job_id,
            content=content,
            content_type=document.content_type or "application/octet-stream",
            expected_type=expected_type,
        )
    except InvalidDocumentError as error:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail=str(error),
        ) from error
