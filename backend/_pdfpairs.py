import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

# On reconstruit les lignes : texte brut, puis on cherche les motifs connus.
# Chaque laureat comporte : NOM, NUM (ex 96AS26 / 39 IP24 / 10 IA25), CIN (ex FA211848).
text = "\n".join(page.get_text() for page in doc)
text = re.sub(r"[ \t]+", " ", text)

# Numeros d'inscription : 1-3 chiffres + AS/IP/IA + 2 chiffres
num_re = re.compile(r"\b(\d{1,3})\s*(AS|IP|IA)\s*(\d{2})\b")
# CIN marocain : 1-2 lettres + 5-7 chiffres (FA211848, FJ6860, SZ19587, F772567, U197938...)
cin_re = re.compile(r"\b([A-Z]{1,2}\d{5,7})\b")

nums = [(m.start(), f"{m.group(1)}{m.group(2)}{m.group(3)}") for m in num_re.finditer(text)]
cins = [(m.start(), m.group(1)) for m in cin_re.finditer(text)]

out = []
out.append(f"pages={doc.page_count}")
out.append(f"numeros trouves={len(nums)}")
out.append(f"CINs trouves={len(cins)}")
out.append("")
out.append("Le plus proche CIN apres chaque numero :")
for pos, num in nums:
    suivants = [c for p, c in cins if p > pos]
    cin = suivants[0] if suivants else "??"
    out.append(f"  {num:10s} -> {cin}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_pairs.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("\n".join(out[:12]))
print("...")
