import pymupdf, re, io

path = r"C:\Users\pc\Desktop\Gestion des notes V2\word\LAUREATS_2025_2026_CIN_EXACT.pdf"
doc = pymupdf.open(path)

AR = re.compile(r"[\u0600-\u06FF]")

# Page 3 : toutes les lignes arabes avec y, pour voir ou est le nom de NABIL HAJI.
page = doc[2]
raw = page.get_text("rawdict")
out = []
for block in raw.get("blocks", []):
    for line in block.get("lines", []):
        chars = []
        for span in line.get("spans", []):
            for ch in span.get("chars", []):
                c = ch["c"]
                if AR.match(c) or c == "\u0640":
                    x0, y0, x1, y1 = ch["bbox"]
                    chars.append({"c": c, "x0": x0, "y": (y0+y1)/2})
        if not chars:
            continue
        chars.sort(key=lambda a: a["x0"])
        y = round(chars[0]["y"], 1)
        txt = "".join(c["c"] for c in chars)
        xr = (round(chars[0]["x0"]), round(chars[-1]["x0"]))
        out.append(f"y={y:6.1f} x={xr} | {txt}")

out.sort(key=lambda s: float(s.split("y=")[1].split()[0]))
with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_p3_lines.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))
print("ok", len(out))
