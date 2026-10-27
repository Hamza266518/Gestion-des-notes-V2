import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")
NUMROW = re.compile(r"^\d{1,3}(AS|IP|IA)\d{2}$")

# 1) Recuperer, pour chaque page, toutes les "lignes" de mots (via words) pour reperer
#    la ligne du numero d'inscription (x ~ 230) et sa bande y.
# 2) Recuperer TOUS les caracteres arabes avec bbox.
# 3) Pour chaque numero : prendre les caracteres arabes dans la bande y du numero (+-9px),
#    dans la colonne NOM (x entre 300 et 470), les ordonner par x decroissant (droite->gauche),
#    et reconstruire.

records = []
for pi, page in enumerate(doc):
    words = page.get_text("words")
    # reperer les lignes contenant un numero d'inscription
    nums = []
    for w in words:
        if NUMROW.match(w[4]):
            nums.append((w[4], w[1], w[3]))  # num, y0, y1
    # forme "39 IP24" : chiffres puis code sur des mots separes
    for i, w in enumerate(words):
        if re.fullmatch(r"\d{1,3}", w[4]):
            for w2 in words:
                if re.fullmatch(r"(AS|IP|IA)\d{2}", w2[4]) and abs(w2[1] - w[1]) < 3 and 0 < w2[0] - w[0] < 45:
                    nums.append((w[4] + w2[4], w[1], w[3]))

    raw = page.get_text("rawdict")
    chars = []
    for block in raw.get("blocks", []):
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                for ch in span.get("chars", []):
                    c = ch["c"]
                    if AR.match(c) or c == "\u0640":
                        x0, y0, x1, y1 = ch["bbox"]
                        chars.append({"c": c, "x0": x0, "x1": x1, "y": (y0 + y1) / 2})

    for num, y0, y1 in nums:
        yc = (y0 + y1) / 2
        # bande verticale : la ligne du numero + les fragments de nom eventuellement plus haut
        band = [ch for ch in chars if abs(ch["y"] - yc) < 11]
        # colonne NOM : x de 300 a 470 (le nom arabe est juste a gauche de la date)
        nom_chars = [ch for ch in band if 295 <= ch["x1"] <= 480]
        if not nom_chars:
            # Parfois le nom est carrement a droite (colonne النسب)
            nom_chars = [ch for ch in band if ch["x0"] > 295]
        # ordonner de droite a gauche
        nom_chars.sort(key=lambda a: -a["x0"])
        txt = PRES.sub("", "".join(a["c"] for a in nom_chars))
        records.append({"num": num, "page": pi + 1, "y": round(yc, 1), "nom_ar": txt, "n": len(nom_chars)})

# Dedup
seen = {}
for r in records:
    if r["num"] not in seen or r["n"] > seen[r["num"]]["n"]:
        seen[r["num"]] = r

json.dump(list(seen.values()), io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_final.json", "w", encoding="utf-8"), ensure_ascii=False, indent=2)

out = [f"records={len(seen)}"]
for num, r in seen.items():
    out.append(f"{num:10s} n={r['n']:3d} | {r['nom_ar']}")
with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_final.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("records:", len(seen))
