import pymupdf, re, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

# Test 1 : la police d'un mot arabe. Si pas de ToUnicode -> on ne peut pas decoder.
page = doc[0]
d = page.get_text("rawdict")
found = 0
lines = []
for block in d.get("blocks", []):
    for line in block.get("lines", []):
        for span in line.get("spans", []):
            for ch in span.get("chars", []):
                c = ch["c"]
                if c == "?" or (0x0600 <= ord(c) <= 0x06FF):
                    lines.append({
                        "c": c,
                        "font": span["font"],
                        "origin": [round(v, 1) for v in ch["origin"]],
                    })
                    found += 1
                    if found > 40:
                        break
            if found > 40:
                break
        if found > 40:
            break
    if found > 40:
        break

out = f"chars arabes/?: {found}\n"
for l in lines:
    out += f"  c={l['c']!r} font={l['font']} origin={l['origin']}\n"

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arpdf_probe.txt", "w", encoding="utf-8") as f:
    f.write(out)

# Test 2 : les polices du document
fonts = set()
for p in doc:
    for f in p.get_fonts():
        fonts.add((f[3], f[4]))  # (basefont, name)
out2 = "fonts:\n" + "\n".join(f"  {a} | {b}" for a, b in sorted(fonts))
with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_arpdf_fonts.txt", "w", encoding="utf-8") as f:
    f.write(out2)

print("done")
