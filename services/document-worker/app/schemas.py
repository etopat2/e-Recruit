from typing import Literal

from pydantic import BaseModel, Field


class BoundingBox(BaseModel):
    page: int = Field(ge=1)
    x: float = Field(ge=0)
    y: float = Field(ge=0)
    width: float = Field(ge=0)
    height: float = Field(ge=0)


class ExtractedField(BaseModel):
    key: str
    value: str
    confidence: float = Field(ge=0, le=1)
    bounding_box: BoundingBox | None = None


class QualityIndicators(BaseModel):
    blur_score: float
    overexposure_ratio: float = Field(ge=0, le=1)
    low_resolution: bool
    probable_clipping: bool
    warnings: list[str]


class PageResult(BaseModel):
    page: int
    width: int
    height: int
    raw_text: str
    mean_confidence: float = Field(ge=0, le=1)
    quality: QualityIndicators
    words: list[ExtractedField]


class ProcessingResult(BaseModel):
    job_id: str
    expected_type: str
    classified_type: str
    status: Literal["processed", "low_confidence", "ocr_unavailable"]
    engine: str
    engine_version: str
    pages: list[PageResult]
    structured_fields: list[ExtractedField]
    warnings: list[str]
