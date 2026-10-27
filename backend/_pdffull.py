import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
PRES = re.compile(r"[\u0640\u064B-\u0652]")
NUMROW = re.compile(r"^\d{1,3}(AS|IP|IA)\d{2}$")
DATE = re.compile(r"^\d{2}/\d{2}/\d{4}$")
CIN = re.compile(r"^[A-Z]{1,2}\d{4,7}$")

# Pour chaque laureat : recuperer TOUT ce qui est sur sa ligne :
#   - numero, date inscription, CIN, date naissance  (colonnes a gauche/centre)
#   - nom arabe (colonne droite)
#   - lieu de naissance (colonne du milieu)
records = []
for pi, page in enumerate(doc):
    words = page.get_text("words")
    # lignes de numero
    nums = []
    for w in words:
        if NUMROW.match(w[4]):
            nums.append((w[4], w[1], w[3]))
    for w in words:
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

        # Mots latins/chiffres sur cette ligne : tolerance generreuse car les
        # tableaux IP/IA ont des hauteurs de ligne plus grandes.
        row = [w for w in words if abs((w[1] + w[3]) / 2 - yc) < 12]
        row.sort(key=lambda w: w[0])
        # retirer les mots du numero lui-meme
        numset = {num, num[:len(num)-4] if len(num) > 4 else num}
        rest = []
        # trouver l'index du CHIFFRE du numero puis prendre tout apres
        start = None
        for i, w in enumerate(row):
            if re.fullmatch(r"\d{1,3}", w[4]) and w[4] == num[:len(num)-4]:
                start = i
                break
            if w[4] == num:
                start = i
                break
        if start is not None:
            rest = row[start + 1:]
        dinsc = cin = dnaiss = None
        for w in rest:
            t = w[4]
            if dinsc is None and DATE.match(t):
                dinsc = t
            elif cin is None and CIN.match(t):
                cin = t
            elif dnaiss is None and DATE.match(t):
                dnaiss = t

        # caracteres arabes de la bande : separer nom (droite) et lieu (gauche) par le plus grand trou
        band = [ch for ch in chars if abs(ch["y"] - yc) < 10] or [ch for ch in chars if abs(ch["y"] - yc) < 16]
        band.sort(key=lambda a: a["x0"])
        lieu_ar = nom_ar = ""
        if band:
            gaps = [(band[i]["x0"] - band[i - 1]["x1"], i) for i in range(1, len(band))]
            name_part = band
            lieu_part = []
            if gaps:
                g, ix = max(gaps, key=lambda z: z[0])
                if g > 12:
                    name_part = band[ix:]
                    lieu_part = band[:ix]
            nom_ar = PRES.sub("", "".join(c["c"] for c in sorted(name_part, key=lambda a: -a["x0"])))
            lieu_ar = PRES.sub("", "".join(c["c"] for c in sorted(lieu_part, key=lambda a: -a["x0"])))

        records.append({
            "num": num, "page": pi + 1, "y": round(yc, 1),
            "dinsc": dinsc, "cin": cin, "dnaiss": dnaiss,
            "nom_ar": nom_ar, "lieu_ar": lieu_ar,
        })

seen = {}
for r in records:
    if r["num"] not in seen or len(r["nom_ar"]) > len(seen[r["num"]]["nom_ar"]):
        seen[r["num"]] = r

json.dump(list(seen.values()), io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_pdf_full.json", "w", encoding="utf-8"), ensure_ascii=False, indent=2)

out = [f"records={len(seen)}"]
for num, r in seen.items():
    out.append(f"{num:10s} | cin={str(r['cin']):10s} | naiss={str(r['dnaiss']):11s} | insc={str(r['dinsc']):11s} | nom_ar={r['nom_ar']} | lieu_ar={r['lieu_ar']}")
with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_pdf_full.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("records:", len(seen))
