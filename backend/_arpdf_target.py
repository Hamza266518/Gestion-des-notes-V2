import pymupdf, re, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")

# On cherche les lignes contenant "وداد" (Widad) et "شهرزاد" (Chahrazad)
targets = {"وداد": "Widad ALLA", "شهرزاد": "Chahrazad (56 IP24 / 47 IP24)"}

out = []
for pi, page in enumerate(doc):
    d = page.get_text("rawdict")
    for block in d.get("blocks", []):
        for line in block.get("lines", []):
            chars = []
            for span in line.get("spans", []):
                for ch in span.get("chars", []):
                    if AR.match(ch["c"]) or ch["c"] == "\u0640":
                        chars.append((ch["origin"][0], ch["c"]))
            if not chars:
                continue
            chars.sort(key=lambda a: a[0])
            visuel = "".join(c for _, c in chars)
            logique = PRES.sub("", visuel)[::-1]
            for t in targets:
                if t in logique:
                    # on affiche chaque caractere avec son x pour voir la structure
                    detail = " ".join(f"{c}@{x:.1f}" for x, c in sorted(chars, key=lambda a: a[0]))
                    out.append(f"p{pi+1} y={line['bbox'][1]:.1f} [{targets[t]}]")
                    out.append(f"   logique = {logique}")
                    out.append(f"   chars   = {detail}")
                    out.append("")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arpdf_target.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("ok")
