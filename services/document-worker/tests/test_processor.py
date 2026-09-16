from io import BytesIO

from PIL import Image, ImageDraw

from app.processor import DocumentProcessor, InvalidDocumentError
from app.schemas import ExtractedField, PageResult, QualityIndicators
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


def extracted_fields(text: str) -> list[ExtractedField]:
    page = PageResult(
        page=1,
        width=900,
        height=600,
        raw_text=text,
        mean_confidence=0.96,
        quality=QualityIndicators(
            blur_score=100,
            overexposure_ratio=0,
            low_resolution=False,
            probable_clipping=False,
            warnings=[],
        ),
        words=[],
    )
    return DocumentProcessor._extract_fields(text, [page])


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


def test_national_id_date_of_birth_is_read_as_day_month_year() -> None:
    fields = extracted_fields("NATIONAL ID DATE OF BIRTH 31.08.2002")

    date_of_birth = next(field for field in fields if field.key == "dob")
    assert date_of_birth.value == "31.08.2002"


def test_national_id_date_of_birth_rejects_month_first_and_invalid_dates() -> None:
    month_first = extracted_fields("NATIONAL ID DATE OF BIRTH 08.31.2002")
    invalid_calendar_date = extracted_fields("NATIONAL ID DATE OF BIRTH 31.02.2002")

    assert all(field.key != "dob" for field in month_first)
    assert all(field.key != "dob" for field in invalid_calendar_date)
