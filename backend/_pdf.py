import pymupdf
import sys, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

out = []
out.append(f"pages: {doc.page_count}")
for i, page in enumerate(doc):
    out.append(f"===== PAGE {i+1} =====")
    out.append(page.get_text())

text = "\n".join(out)
with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_laureats.txt", "w", encoding="utf-8") as f:
    f.write(text)

print(f"written {len(text)} bytes, pages={doc.page_count}")
print(text[:3000])
