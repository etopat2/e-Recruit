from io import BytesIO

from PIL import Image, ImageDraw

from app.processor import DocumentProcessor, InvalidDocumentError
from app.settings import Settings


def make_settings() -> Settings:
    return Settings(
        document_worker_token="synthetic-worker-token",
        tesseract_cmd="definitely-unavailable-tesseract",
    )


def synthetic_png() -> bytes:
    image = Image.new("RGB", (900, 600), "white")
    draw = ImageDraw.Draw(image)
    draw.text((80, 120), "SYNTHETIC ID CM123456789AB DOB 13/08/2002", fill="black")
    output = BytesIO()
    image.save(output, format="PNG")
    return output.getvalue()


def test_valid_image_is_retained_as_reviewable_when_ocr_is_unavailable() -> None:
    result = DocumentProcessor(make_settings()).process(
        job_id="synthetic-job-001",
        content=synthetic_png(),
        content_type="image/png",
        expected_type="national_id",
    )

    assert result.status == "ocr_unavailable"
    assert result.pages[0].page == 1
    assert "human review" in result.warnings[-1].lower()


def test_malformed_image_is_rejected_before_ocr() -> None:
    try:
        DocumentProcessor(make_settings()).process(
            job_id="synthetic-job-002",
            content=b"not-an-image",
            content_type="image/png",
            expected_type="national_id",
        )
    except InvalidDocumentError as error:
        assert str(error) == "Malformed image document."
    else:
        raise AssertionError("Malformed image should not be processed.")
