import pymupdf, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

# Page 3, autour de y=103 (39IP24) : lister TOUS les mots avec x,y
page = doc[2]
words = page.get_text("words")
out = []
for w in sorted(words, key=lambda w: (round(w[1],0), w[0])):
    if 95 < w[1] < 115:
        out.append(f"x0={w[0]:7.1f} y0={w[1]:6.1f} y1={w[3]:6.1f} | {w[4]!r}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ip_row.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))
print("ok")
