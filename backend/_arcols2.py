import pymupdf, re, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")

# Page 3 (IP), ligne 39IP24 y~97 : afficher tous les chars arabes avec x
out = []
for pi, target_y, label in [(2, 97.2, "39IP24 NABIL HAJI"), (2, 114.9, "40IP24 HAYAT HADDOU"), (3, 191.5, "11IA25 SABRINA")]:
    page = doc[pi]
    raw = page.get_text("rawdict")
    chars = []
    for block in raw.get("blocks", []):
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                for ch in span.get("chars", []):
                    c = ch["c"]
                    if AR.match(c) or c == "\u0640":
                        x0, y0, x1, y1 = ch["bbox"]
                        chars.append({"c": c, "x0": round(x0,1), "y": round((y0+y1)/2,1)})
    band = [c for c in chars if abs(c["y"] - target_y) < 8]
    band.sort(key=lambda a: a["x0"])
    line = " ".join(f"{c['c']}@{c['x0']:.0f}" for c in band)
    out.append(f"--- {label} y={target_y} ---")
    out.append("   " + line)

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arcols2.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))
print("ok")
