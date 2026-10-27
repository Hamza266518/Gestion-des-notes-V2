import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")
LAT = re.compile(r"^[A-Za-z'\-\.]+$")
NUM = re.compile(r"^\d{1,3}(AS|IP|IA)\d{2}$")

out = []
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
        ligne.append((x0, txt))
    if ligne:
        lignes.append((ligne_y, ligne))

    for y, toks in lignes:
        toks = [t for _, t in toks]
        # Numero
        idx = None
        for i, t in enumerate(toks):
            if NUM.match(t):
                idx = i
                break
        if idx is None:
            continue
        # Mots arabes autour (nom et prenom arabes)
        arabes = [t for t in toks if AR.search(t)]
        # Les mots latins AVANT le numero = nom francais
        avant = toks[:idx]
        latins = [t for t in avant if LAT.match(t)]
        records.append({
            "page": pi + 1,
            "num": toks[idx],
            "ar": arabes,
            "fr": " ".join(latins),
            "tokens": toks,
        })

# Dedup par numero, en fusionnant les mots arabes
seen = {}
for r in records:
    n = r["num"]
    if n not in seen:
        seen[n] = r
    else:
        for t in r["ar"]:
            if t not in seen[n]["ar"]:
                seen[n]["ar"].append(t)
        if len(r["fr"]) > len(seen[n]["fr"]):
            seen[n]["fr"] = r["fr"]

for n, r in seen.items():
    out.append(f"{n:10s} | fr={r['fr']:32s} | ar={' '.join(r['ar'])}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_ar.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

json.dump(list(seen.values()),
          io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_ar.json", "w", encoding="utf-8"),
          ensure_ascii=False, indent=2)

print("records:", len(seen))
