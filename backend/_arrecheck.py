import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")
NUM = re.compile(r"(\d{1,3})\s*(AS|IP|IA)\s*(\d{2})")

# Extraction fine : pour chaque ligne, on recupere les caracteres arabes
# avec leur x ET leur largeur (bbox) pour reconstruire l'ordre reel.
out = []
alllines = []

for pi, page in enumerate(doc):
    d = page.get_text("rawdict")
    for block in d.get("blocks", []):
        for line in block.get("lines", []):
            chars = []
            for span in line.get("spans", []):
                for ch in span.get("chars", []):
                    c = ch["c"]
                    if AR.match(c) or c == "\u0640":
                        x0, y0, x1, y1 = ch["bbox"]
                        chars.append({"c": c, "x0": x0, "x1": x1, "xc": (x0 + x1) / 2})
            if not chars:
                continue
            # Tri par centre de caractere (et non l'origine, qui bugge sur les ligatures)
            chars.sort(key=lambda a: a["xc"])
            visuel = "".join(a["c"] for a in chars)
            logique = PRES.sub("", visuel)[::-1]
            alllines.append({
                "page": pi + 1,
                "y": round(line["bbox"][1], 1),
                "visuel": visuel,
                "logique": logique,
                "n": len(chars),
            })

# Ecrire toutes les lignes arabes triees
alllines.sort(key=lambda a: (a["page"], a["y"]))
with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_all_lines.txt", "w", encoding="utf-8") as f:
    for a in alllines:
        f.write(f"p{a['page']} y={a['y']:6.1f} n={a['n']:3d} | {a['logique']}\n")

json.dump(alllines, io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_all_lines.json", "w", encoding="utf-8"), ensure_ascii=False, indent=2)

print("lines:", len(alllines))
