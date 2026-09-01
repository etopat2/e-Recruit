from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", extra="ignore")

    document_worker_token: str = Field(min_length=12)
    ocr_engine: str = "tesseract"
    tesseract_cmd: str = "tesseract"
    ocr_timeout_seconds: int = Field(default=45, ge=5, le=120)
    max_document_bytes: int = Field(default=15 * 1024 * 1024, ge=1024)
    max_pdf_pages: int = Field(default=20, ge=1, le=100)
    min_image_width: int = Field(default=640, ge=100)
    min_image_height: int = Field(default=480, ge=100)


@lru_cache
def get_settings() -> Settings:
    return Settings()
