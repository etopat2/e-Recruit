from __future__ import annotations

import re
import shutil
from dataclasses import dataclass
from io import BytesIO

import cv2
import fitz
import numpy as np
import pytesseract
from PIL import Image, UnidentifiedImageError
from pytesseract import Output

from .schemas import BoundingBox, ExtractedField, PageResult, ProcessingResult, QualityIndicators
from .settings import Settings


class InvalidDocumentError(ValueError):
    """Raised when an upload is unsupported or unsafe to process."""


@dataclass(frozen=True)
class DecodedPage:
    page: int
    image: np.ndarray


class DocumentProcessor:
    allowed_mime_types = {"application/pdf", "image/jpeg", "image/png"}

    def __init__(self, settings: Settings) -> None:
        self.settings = settings
        pytesseract.pytesseract.tesseract_cmd = settings.tesseract_cmd

    def process(
        self,
        *,
        job_id: str,
        content: bytes,
        content_type: str,
        expected_type: str,
    ) -> ProcessingResult:
        if len(content) > self.settings.max_document_bytes:
            raise InvalidDocumentError("Document exceeds the configured processing limit.")
        if content_type not in self.allowed_mime_types:
            raise InvalidDocumentError("Document type is not approved for OCR processing.")

        pages = self._decode_pages(content, content_type)
        tesseract_available = shutil.which(self.settings.tesseract_cmd) is not None
        results = [self._process_page(page, tesseract_available) for page in pages]
        combined_text = "\n".join(page.raw_text for page in results)
        structured_fields = self._extract_fields(combined_text, results)
        confidence = min((page.mean_confidence for page in results), default=0.0)
        warnings = [warning for page in results for warning in page.quality.warnings]
        status = "processed"
        if not tesseract_available:
            status = "ocr_unavailable"
            warnings.append("OCR engine is unavailable; human review remains required.")
        elif confidence < 0.55:
            status = "low_confidence"
            warnings.append(
                "OCR confidence is low; this result must not trigger automatic rejection."
            )

        engine_version = (
            str(pytesseract.get_tesseract_version()) if tesseract_available else "unavailable"
        )

        return ProcessingResult(
            job_id=job_id,
            expected_type=expected_type,
            classified_type=self._classify(combined_text, expected_type),
            status=status,
            engine=self.settings.ocr_engine,
            engine_version=engine_version,
            pages=results,
            structured_fields=structured_fields,
            warnings=sorted(set(warnings)),
        )

    def _decode_pages(self, content: bytes, content_type: str) -> list[DecodedPage]:
        if content_type == "application/pdf":
            try:
                document = fitz.open(stream=content, filetype="pdf")
            except (fitz.FileDataError, RuntimeError) as error:
                raise InvalidDocumentError("Malformed PDF document.") from error
            if document.page_count > self.settings.max_pdf_pages:
                document.close()
                raise InvalidDocumentError("PDF has too many pages.")
            pages = []
            try:
                for page_number, page in enumerate(document, start=1):
                    pixmap = page.get_pixmap(matrix=fitz.Matrix(1.5, 1.5), alpha=False)
                    image = np.frombuffer(pixmap.samples, dtype=np.uint8).reshape(
                        pixmap.height, pixmap.width, pixmap.n
                    )
                    pages.append(
                        DecodedPage(
                            page=page_number,
                            image=cv2.cvtColor(image, cv2.COLOR_RGB2BGR),
                        )
                    )
            finally:
                document.close()
            return pages

        try:
            pil_image = Image.open(BytesIO(content))
            pil_image.verify()
            pil_image = Image.open(BytesIO(content)).convert("RGB")
        except (UnidentifiedImageError, OSError) as error:
            raise InvalidDocumentError("Malformed image document.") from error
        image = cv2.cvtColor(np.asarray(pil_image), cv2.COLOR_RGB2BGR)
        return [DecodedPage(page=1, image=image)]

    def _process_page(self, decoded: DecodedPage, tesseract_available: bool) -> PageResult:
        image = self._deskew(decoded.image)
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        quality = self._quality(gray)
        if not tesseract_available:
            return PageResult(
                page=decoded.page,
                width=image.shape[1],
                height=image.shape[0],
                raw_text="",
                mean_confidence=0.0,
                quality=quality,
                words=[],
            )

        data = pytesseract.image_to_data(
            gray,
            output_type=Output.DICT,
            timeout=self.settings.ocr_timeout_seconds,
        )
        words: list[ExtractedField] = []
        confidences: list[float] = []
        raw_words: list[str] = []
        for index, text in enumerate(data["text"]):
            text = text.strip()
            confidence = max(float(data["conf"][index]), 0.0) / 100
            if not text:
                continue
            raw_words.append(text)
            confidences.append(confidence)
            words.append(
                ExtractedField(
                    key="word",
                    value=text,
                    confidence=confidence,
                    bounding_box=BoundingBox(
                        page=decoded.page,
                        x=float(data["left"][index]),
                        y=float(data["top"][index]),
                        width=float(data["width"][index]),
                        height=float(data["height"][index]),
                    ),
                )
            )
        return PageResult(
            page=decoded.page,
            width=image.shape[1],
            height=image.shape[0],
            raw_text=" ".join(raw_words),
            mean_confidence=float(np.mean(confidences)) if confidences else 0.0,
            quality=quality,
            words=words,
        )

    def _quality(self, gray: np.ndarray) -> QualityIndicators:
        height, width = gray.shape
        blur_score = float(cv2.Laplacian(gray, cv2.CV_64F).var())
        overexposure_ratio = float(np.mean(gray >= 245))
        low_resolution = (
            width < self.settings.min_image_width
            or height < self.settings.min_image_height
        )
        edge_pixels = np.concatenate([gray[0, :], gray[-1, :], gray[:, 0], gray[:, -1]])
        probable_clipping = float(np.mean(edge_pixels < 235)) > 0.35
        warnings = []
        if blur_score < 65:
            warnings.append("Image may be blurred.")
        if overexposure_ratio > 0.45:
            warnings.append("Image may contain glare or overexposure.")
        if low_resolution:
            warnings.append("Image resolution is below the recommended minimum.")
        if probable_clipping:
            warnings.append("Document content may touch or cross image edges.")
        return QualityIndicators(
            blur_score=round(blur_score, 2),
            overexposure_ratio=round(overexposure_ratio, 4),
            low_resolution=low_resolution,
            probable_clipping=probable_clipping,
            warnings=warnings,
        )

    @staticmethod
    def _deskew(image: np.ndarray) -> np.ndarray:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        coordinates = np.column_stack(np.where(gray < 230))
        if len(coordinates) < 100:
            return image
        angle = cv2.minAreaRect(coordinates)[-1]
        angle = -(90 + angle) if angle < -45 else -angle
        if abs(angle) < 0.35 or abs(angle) > 15:
            return image
        height, width = image.shape[:2]
        matrix = cv2.getRotationMatrix2D((width / 2, height / 2), angle, 1.0)
        return cv2.warpAffine(image, matrix, (width, height), borderMode=cv2.BORDER_REPLICATE)

    @staticmethod
    def _classify(text: str, expected_type: str) -> str:
        upper = text.upper()
        if re.search(r"\b[A-Z]{2}\d{8,12}[A-Z0-9]{1,4}\b", upper):
            return "national_id"
        if "UGANDA CERTIFICATE OF EDUCATION" in upper or "INDEX NUMBER" in upper:
            return "academic"
        if "LOCAL COUNCIL" in upper or "LC1" in upper:
            return "lc_recommendation"
        return expected_type

    @staticmethod
    def _extract_fields(text: str, pages: list[PageResult]) -> list[ExtractedField]:
        patterns = {
            "nin": r"\b[A-Z]{2}\d{8,12}[A-Z0-9]{1,4}\b",
            "dob": r"\b(?:0?[1-9]|[12]\d|3[01])[-/.](?:0?[1-9]|1[0-2])[-/.](?:19|20)\d{2}\b",
            "index_number": (
                r"\b(?:INDEX(?:\s+NO(?:\.|:)?|\s+NUMBER)?\s*)?"
                r"([A-Z0-9]{4,}[/-][A-Z0-9/.-]+)\b"
            ),
        }
        fields = []
        mean_confidence = min((page.mean_confidence for page in pages), default=0.0)
        for key, pattern in patterns.items():
            match = re.search(pattern, text.upper())
            if match:
                fields.append(
                    ExtractedField(
                        key=key,
                        value=match.group(1) if match.lastindex else match.group(0),
                        confidence=mean_confidence,
                    )
                )
        return fields
