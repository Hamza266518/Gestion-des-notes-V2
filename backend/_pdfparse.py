import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

# On regroupe les mots par ligne (y), puis on decoupe chaque ligne en "records".
# Chaque record terminant par un numero d'inscription (ex 96AS26 ou 39 IP24) et un CIN.
num_re = re.compile(r"^\d{1,3}$|^(AS|IP|IA)\d{2}$")
cin_re = re.compile(r"^[A-Z]{1,2}\d{4,7}$")
date_re = re.compile(r"^\d{2}/\d{2}/\d{4}$")

records = []

for pi, page in enumerate(doc):
    words = page.get_text("words")
    ws = sorted(words, key=lambda w: (round(w[1], 0), w[0]))

    lignes = []
    ligne_y = None
    ligne = []
    for w in ws:
        x0, y0, x1, y1, txt = w[0], w[1], w[2], w[3], w[4]
        if ligne_y is None or abs(y0 - ligne_y) > 3:
            if ligne:
                lignes.append((ligne_y, ligne))
            ligne = []
            ligne_y = y0
        ligne.append(txt)
    if ligne:
        lignes.append((ligne_y, ligne))

    # Reconstitution : le numero d'inscription est soit "96AS26" (colle),
    # soit "39","IP24" (deux mots) soit "10","IA25" (deux mots).
    cur = None
    for y, toks in lignes:
        # On cherche un numero dans la ligne
        # Cas 1 : un seul token colle  NNNAA NN
        idx_num = None
        for i, t in enumerate(toks):
            if re.fullmatch(r"\d{1,3}(AS|IP|IA)\d{2}", t):
                idx_num = i
                break
            if re.fullmatch(r"\d{1,3}", t) and i + 1 < len(toks) and re.fullmatch(r"(AS|IP|IA)\d{2}", toks[i + 1]):
                idx_num = i
                break

        if idx_num is not None:
            # Numero
            if re.fullmatch(r"\d{1,3}(AS|IP|IA)\d{2}", toks[idx_num]):
                num = toks[idx_num]
                rest = toks[idx_num + 1:]
            else:
                num = toks[idx_num] + toks[idx_num + 1]
                rest = toks[idx_num + 2:]

            # Apres le numero : date inscription, CIN, date naissance
            dinsc = None
            cin = None
            dnaiss = None
            for t in rest:
                if dinsc is None and date_re.match(t):
                    dinsc = t
                    continue
                if cin is None and cin_re.match(t):
                    cin = t
                    continue
                if dnaiss is None and date_re.match(t):
                    dnaiss = t
                    continue

            # Avant le numero : le nom (mots latins) et le lieu (mots arabes)
            avant = toks[:idx_num]
            latins = []
            for t in avant:
                if re.fullmatch(r"[A-Za-z'\-\.]+", t):
                    latins.append(t)
                elif re.search(r"[\u0600-\u06FF]", t):
                    pass
            nom = " ".join(latins).strip()

            # Le nom peut deborder sur la ligne precedente (deja capturee) ou suivante.
            records.append({
                "page": pi + 1,
                "y": round(y, 1),
                "num": num,
                "dinsc": dinsc,
                "cin": cin,
                "dnaiss": dnaiss,
                "nom_before": nom,
            })

# Deduplication : un meme num ne doit apparaitre qu'une fois
seen = {}
for r in records:
    if r["num"] not in seen:
        seen[r["num"]] = r
    else:
        # On complete les champs manquants
        for k in ("dinsc", "cin", "dnaiss"):
            if not seen[r["num"]][k] and r[k]:
                seen[r["num"]][k] = r[k]
        if len(r["nom_before"]) > len(seen[r["num"]]["nom_before"]):
            seen[r["num"]]["nom_before"] = r["nom_before"]

out = []
out.append(f"records={len(seen)}")
for num, r in seen.items():
    out.append(f"{num:10s} | cin={str(r['cin']):12s} | naiss={str(r['dnaiss']):12s} | insc={str(r['dinsc']):12s} | nom_avant={r['nom_before']}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_parsed.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

json.dump(list(seen.values()),
          io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_parsed.json", "w", encoding="utf-8"),
          ensure_ascii=False, indent=2)

print("records:", len(seen))
