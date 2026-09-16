"""Generate deterministic UPS e-Recruit icons from the supplied official logo."""

from pathlib import Path

from PIL import Image, ImageDraw


WEB_ROOT = Path(__file__).resolve().parents[1]
PUBLIC_ROOT = WEB_ROOT / "public"
ICON_ROOT = PUBLIC_ROOT / "icons"
LOGO_PATH = PUBLIC_ROOT / "brand" / "ups-logo.png"

BURGUNDY = (104, 22, 44, 255)
GOLD = (225, 183, 69, 255)
TRANSPARENT = (0, 0, 0, 0)
SUPERSAMPLE = 4


def contain(image: Image.Image, width: int, height: int) -> Image.Image:
    scale = min(width / image.width, height / image.height)
    size = (max(1, round(image.width * scale)), max(1, round(image.height * scale)))
    return image.resize(size, Image.Resampling.LANCZOS)


def render_icon(size: int, *, transparent_corners: bool, safe_zone: bool = False) -> Image.Image:
    working_size = size * SUPERSAMPLE
    canvas = Image.new("RGBA", (working_size, working_size), TRANSPARENT if transparent_corners else BURGUNDY)
    draw = ImageDraw.Draw(canvas)

    if transparent_corners:
        radius = round(working_size * 0.145)
        draw.rounded_rectangle((0, 0, working_size - 1, working_size - 1), radius=radius, fill=BURGUNDY)
        border_inset = round(working_size * 0.035)
        border_width = max(SUPERSAMPLE, round(working_size * 0.012))
        draw.rounded_rectangle(
            (border_inset, border_inset, working_size - border_inset - 1, working_size - border_inset - 1),
            radius=max(1, radius - border_inset),
            outline=GOLD,
            width=border_width,
        )
    else:
        border_inset = round(working_size * 0.09)
        border_width = max(SUPERSAMPLE, round(working_size * 0.012))
        draw.rounded_rectangle(
            (border_inset, border_inset, working_size - border_inset - 1, working_size - border_inset - 1),
            radius=round(working_size * 0.11),
            outline=GOLD,
            width=border_width,
        )

    logo = Image.open(LOGO_PATH).convert("RGBA")
    logo_height = round(working_size * (0.66 if safe_zone else 0.79))
    logo_width = round(working_size * (0.66 if safe_zone else 0.72))
    logo = contain(logo, logo_width, logo_height)
    position = ((working_size - logo.width) // 2, (working_size - logo.height) // 2)
    canvas.alpha_composite(logo, position)

    rendered = canvas.resize((size, size), Image.Resampling.LANCZOS)
    if transparent_corners:
        alpha = rendered.getchannel("A").point(lambda value: 0 if value < 64 else value)
        rendered.putalpha(alpha)

    return rendered


def save_png(image: Image.Image, filename: str) -> None:
    image.save(ICON_ROOT / filename, format="PNG", optimize=True)


def main() -> None:
    ICON_ROOT.mkdir(parents=True, exist_ok=True)
    for size in (16, 32, 64, 192, 512):
        filename = f"favicon-{size}.png" if size in (16, 32) else f"app-icon-{size}.png"
        save_png(render_icon(size, transparent_corners=True), filename)

    for size in (192, 512):
        save_png(render_icon(size, transparent_corners=False, safe_zone=True), f"maskable-icon-{size}.png")

    apple_icon = render_icon(180, transparent_corners=False, safe_zone=True)
    save_png(apple_icon, "apple-touch-icon.png")
    render_icon(64, transparent_corners=True).save(
        PUBLIC_ROOT / "favicon.ico",
        format="ICO",
        sizes=[(16, 16), (32, 32), (48, 48), (64, 64)],
    )


if __name__ == "__main__":
    main()
