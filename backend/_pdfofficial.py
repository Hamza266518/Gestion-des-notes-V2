import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

text = "\n".join(page.get_text() for page in doc)

# Numeros d'inscription officiels : 1-3 chiffres + AS/IP/IA + 2 chiffres.
num_re = re.compile(r"\b(\d{1,3})\s*(AS|IP|IA)\s*(\d{2})\b")

laureats = []
seen = set()
for m in num_re.finditer(text):
    key = f"{m.group(1)}{m.group(2)}{m.group(3)}"
    if key in seen:
        continue
    seen.add(key)
    laureats.append(key)

data = {
    "source": "LAUREATS_2025_2026_CIN_EXACT.pdf",
    "count": len(laureats),
    "numeros": laureats,
}

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats_officiels.json", "w", encoding="utf-8") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)

print(f"{len(laureats)} laureats officiels")
print(",".join(laureats))
