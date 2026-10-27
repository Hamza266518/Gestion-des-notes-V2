import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")  # tatweel + diacritiques
NUM = re.compile(r"(\d{1,3})(AS|IP|IA)(\d{2})")

# Pour chaque page : recuperer tous les caracteres arabes avec position,
# reconstruire les mots par ligne (regroupement par y), puis inverser l'ordre visuel.
records = []

for pi, page in enumerate(doc):
    d = page.get_text("rawdict")

    # Tous les caracteres arabes du document, avec x,y
    chars = []
    for block in d.get("blocks", []):
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                for ch in span.get("chars", []):
                    c = ch["c"]
                    if AR.match(c) or c in ("\u0640",):
                        chars.append({"c": c, "x": ch["origin"][0], "y": ch["origin"][1]})

    # Regrouper en "mots" : caracteres proches en x et y
    chars.sort(key=lambda a: (round(a["y"], 0), a["x"]))
    mots = []
    cur = []
    last = None
    for c in chars:
        if last is None or (abs(c["y"] - last["y"]) < 2 and abs(c["x"] - last["x"]) < 6):
            cur.append(c)
        else:
            if cur:
                mots.append(cur)
            cur = [c]
        last = c
    if cur:
        mots.append(cur)

    for m in mots:
        # Ordre visuel : x croissant. Le texte arabe se lit de droite a gauche,
        # donc on inverse pour obtenir l'ordre logique.
        m2 = sorted(m, key=lambda a: a["x"])
        text_visual = "".join(c["c"] for c in m2)
        text_logical = text_visual[::-1]
        # Nettoyer les tatweels de justification
        text_logical = PRES.sub("", text_logical)
        y = round(m2[0]["y"], 1)
        x0 = round(m2[0]["x"], 1)
        x1 = round(m2[-1]["x"], 1)
        records.append({
            "page": pi + 1,
            "y": y,
            "x0": x0,
            "x1": x1,
            "ar": text_logical,
        })

# Trier et grouper par ligne (y)
records.sort(key=lambda r: (r["page"], round(r["y"], 0), r["x0"]))
lines = []
cur_y = None
cur_page = None
cur = []
for r in records:
    k = (r["page"], round(r["y"] / 2))
    if cur_page != r["page"] or cur_y is None or abs(r["y"] - cur_y) > 4:
        if cur:
            lines.append({"page": cur_page, "y": cur_y, "tokens": cur})
        cur = []
        cur_page = r["page"]
        cur_y = r["y"]
    cur.append(r)
if cur:
    lines.append({"page": cur_page, "y": cur_y, "tokens": cur})

out = []
for l in lines:
    # lire de droite a gauche (x decroissant)
    toks = sorted(l["tokens"], key=lambda t: -t["x0"])
    phrase = " ".join(t["ar"] for t in toks)
    out.append(f"p{l['page']} y={l['y']:6.1f} | {phrase}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arpdf_lines.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

json.dump(lines, io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arpdf_lines.json", "w", encoding="utf-8"), ensure_ascii=False, indent=2)

print("lines:", len(lines))
