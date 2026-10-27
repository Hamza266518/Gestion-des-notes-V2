import pymupdf, re, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")

# Page 1, ligne y~56 (Hamza EL ACHI 96AS26) : montrer TOUS les caracteres arabes
# avec leur x pour comprendre la disposition des colonnes.
page = doc[0]
raw = page.get_text("rawdict")
chars = []
for block in raw.get("blocks", []):
    for line in block.get("lines", []):
        for span in line.get("spans", []):
            for ch in span.get("chars", []):
                c = ch["c"]
                if AR.match(c) or c == "\u0640":
                    x0, y0, x1, y1 = ch["bbox"]
                    chars.append({"c": c, "x0": round(x0,1), "x1": round(x1,1), "y": round((y0+y1)/2,1), "font": span["font"]})

# On prend les caracteres dans la bande y autour de 67 (Hamza) et 56 (entete)
out = []
for target_y, label in [(67.2, "HAMZA EL ACHI (96AS26)"), (56.4, "ligne 96AS26"), (74.1, "ANASS ELAOUNI (97AS26)")]:
    band = [c for c in chars if abs(c["y"] - target_y) < 4]
    band.sort(key=lambda a: a["x0"])
    out.append(f"--- {label} (y={target_y}) ---")
    for c in band:
        out.append(f"   {c['c']} x0={c['x0']:7.1f} x1={c['x1']:7.1f} y={c['y']}")
    out.append("")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_cols.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("ok")
