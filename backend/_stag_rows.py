import pymupdf, re, io, json

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\Liste des stagiaires AS-IA-IP1-IP2-IP3 2025-2026.docx"
doc = pymupdf.open(path) if path.endswith(".pdf") else None

# C'est un .docx : on passe par python-docx si dispo, sinon zip/xml.
import zipfile
from xml.etree import ElementTree as ET

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"

with zipfile.ZipFile(path) as z:
    xml = z.read("word/document.xml")

root = ET.fromstring(xml)

# Extraire toutes les cellules de tous les tableaux, avec position
rows = []
for tbl in root.iter(W + "tbl"):
    for tr in tbl.iter(W + "tr"):
        cells = []
        for tc in tr.iter(W + "tc"):
            txt = "".join(t.text or "" for t in tc.iter(W + "t"))
            cells.append(txt.strip())
        rows.append(cells)

out = []
out.append(f"lignes de tableaux : {len(rows)}")
for r in rows:
    out.append(" | ".join(r))

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_stagiaires_rows.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("rows:", len(rows))
