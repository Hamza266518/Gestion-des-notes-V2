"""
Rasterizes the three diplome design PDFs (one per filière type) into A4-landscape
PNG backgrounds used by DiplomeController to build the .docx files.

Why PNG: a .docx embeds a raster image, it cannot embed a PDF page as a
background. The source PDFs are slightly wider than A4 landscape (~1717x1179 pt,
ratio ~1.456 vs 297/210 ~= 1.414), so each page is rendered scaled-to-fit inside
the A4 box and centered with white padding. Nothing is stretched or cropped.

Usage (from the repo root):
    python -m pip install --user pymupdf
    python frontend/scripts/rasterize_diplome_templates.py
"""
import pymupdf
from PIL import Image, ImageOps
from pathlib import Path

A4_LANDSCAPE_300DPI = (3508, 2480)  # 297 x 210 mm @ 300 dpi
ROOT = Path(__file__).resolve().parents[2]
SOURCES = {
    "Q": ROOT / "frontend/src/assets/Q Dip consignes.pdf",
    "T": ROOT / "frontend/src/assets/T Dip consignes.pdf",
    "TS": ROOT / "frontend/src/assets/TS Dip consignes.pdf",
}
OUT_DIR = ROOT / "backend/public/templates/diplomes"


def rasterize(pdf_path, out_path):
    doc = pymupdf.open(pdf_path)
    if doc.page_count != 1:
        print(f"WARN {pdf_path.name}: {doc.page_count} pages, using page 1")
    page = doc[0]
    pw, ph = page.rect.width, page.rect.height
    scale = min(A4_LANDSCAPE_300DPI[0] / pw, A4_LANDSCAPE_300DPI[1] / ph)
    pix = page.get_pixmap(matrix=pymupdf.Matrix(scale, scale), alpha=False)
    img = Image.frombytes("RGB", (pix.width, pix.height), pix.samples)

    canvas = Image.new("RGB", A4_LANDSCAPE_300DPI, "white")
    fit = ImageOps.contain(img, A4_LANDSCAPE_300DPI, method=Image.LANCZOS)
    canvas.paste(fit, ((A4_LANDSCAPE_300DPI[0] - fit.width) // 2,
                       (A4_LANDSCAPE_300DPI[1] - fit.height) // 2))
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    canvas.save(out_path, "PNG")
    return pix.width, pix.height, fit.size


def main():
    for tag, src in SOURCES.items():
        if not src.exists():
            raise SystemExit(f"Missing source PDF: {src}")
        out = OUT_DIR / f"{tag}.png"
        rendered_w, rendered_h, fitted = rasterize(src, out)
        print(f"{tag}: {src.name} -> {out.name} "
              f"(rendered {rendered_w}x{rendered_h}, fitted {fitted[0]}x{fitted[1]})")


if __name__ == "__main__":
    main()