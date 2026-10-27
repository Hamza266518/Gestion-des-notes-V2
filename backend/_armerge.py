import io, json, re, unicodedata

lines = json.load(io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_all_lines.json", encoding="utf-8"))

# On fusionne les fragments d'une meme ligne (meme page, y tres proche) qui sont
# des morceaux de NOM (avant le lieu). On garde les lignes de nom uniquement.
AR = re.compile(r"[\u0600-\u06FF]")

# Fusion par (page, y arrondi a 2px)
groups = {}
for l in lines:
    key = (l["page"], round(l["y"] / 3))
    groups.setdefault(key, []).append(l)

merged = []
for key, ls in groups.items():
    ls.sort(key=lambda a: a["y"])
    # concatener les fragments : plus a droite d'abord (mais on ne peut pas facilement),
    # on garde tel quel : la ligne la plus longue contient souvent tout.
    merged.append({
        "page": key[0],
        "y": min(a["y"] for a in ls),
        "frags": [a["logique"] for a in ls],
        "all": " ".join(a["logique"] for a in ls),
    })

merged.sort(key=lambda a: (a["page"], a["y"]))

out = []
for m in merged:
    out.append(f"p{m['page']} y={m['y']:6.1f} | frags={len(m['frags'])} | {m['all']}")

with io.open(r"C:\Users\pc\Desktop\Gestion des notes V2\backend\_ar_merged.txt", "w", encoding="utf-8") as f:
    f.write("\n".join(out))

print("merged:", len(merged))
