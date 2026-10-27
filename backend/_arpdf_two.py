import pymupdf, re, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

# On cible les deux lignes litigieuses : 142AS26 (page 2, y~287) et 56IP24 (page 3, y~408)
AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")

out = []
for pi in (1, 2):  # page 2 = index1, page 3 = index2
    page = doc[pi]
    d = page.get_text("rawdict")
    for block in d.get("blocks", []):
        for line in block.get("lines", []):
            spans = line.get("spans", [])
            y = line["bbox"][1]
            if pi == 1 and not (280 < y < 300):
                continue
            if pi == 2 and not (400 < y < 420):
                continue
            # caracteres arabes de la ligne, avec x
            chars = []
            for span in spans:
                for ch in span.get("chars", []):
                    if AR.match(ch["c"]) or ch["c"] == "\u0640":
                        chars.append((ch["origin"][0], ch["c"]))
            chars.sort(key=lambda a: a[0])
            visuel = "".join(c for _, c in chars)
            logique = PRES.sub("", visuel)[::-1]
            out.append(f"page{pi+1} y={y:.1f} : visuel={visuel}  logique={logique}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arpdf_two.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("ok")
