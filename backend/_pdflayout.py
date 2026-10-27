import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

pages = []
for page in doc:
    # get_text("words") donne les mots avec leurs positions -> on peut reconstruire les colonnes.
    words = page.get_text("words")
    pages.append(words)

out = []
out.append(f"pages={doc.page_count}")

for pi, words in enumerate(pages):
    out.append(f"===== PAGE {pi+1} : {len(words)} mots =====")
    # Tri par y puis x
    ws = sorted(words, key=lambda w: (round(w[1], 0), w[0]))
    ligne_y = None
    ligne = []
    for w in ws:
        x0, y0, x1, y1, txt = w[0], w[1], w[2], w[3], w[4]
        if ligne_y is None or abs(y0 - ligne_y) > 3:
            if ligne:
                out.append(f"  y={ligne_y:6.1f} | " + " | ".join(ligne))
            ligne = []
            ligne_y = y0
        ligne.append(f"{txt}")
    if ligne:
        out.append(f"  y={ligne_y:6.1f} | " + " | ".join(ligne))

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_layout.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("written", len(out), "lines")
