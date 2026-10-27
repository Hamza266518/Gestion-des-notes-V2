import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

NUM = re.compile(r"(\d{1,3})(AS|IP|IA)(\d{2})")
AR = re.compile(r"[\u0600-\u06FF]")

# On utilise "dict" pour recuperer les spans avec le sens du texte.
all_records = []
for pi, page in enumerate(doc):
    d = page.get_text("dict")
    for block in d.get("blocks", []):
        for line in block.get("lines", []):
            spans = line.get("spans", [])
            txt = "".join(s["text"] for s in spans)
            if NUM.search(txt) or AR.search(txt):
                all_records.append({
                    "page": pi + 1,
                    "y": round(line["bbox"][1], 1),
                    "x": round(line["bbox"][0], 1),
                    "dir": line.get("dir"),
                    "text": txt,
                    "spans": [{"t": s["text"], "dir": s.get("dir"), "font": s["font"]} for s in spans],
                })

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_lines.json", "w", encoding="utf-8") as f:
    json.dump(all_records, f, ensure_ascii=False, indent=2)

print("lines:", len(all_records))
