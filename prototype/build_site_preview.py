#!/usr/bin/env python3
"""Автономный HTML-предпросмотр отчёта сайта: код из site/ + готовые данные (data.json отчёта).

    python3 build_site_preview.py <id-отчёта> <data.json> <out.html>

Нужен, чтобы посмотреть отчёт в браузере без хостинга. Меню ведёт на соседние файлы <id>_preview.html.
"""
import json
import os
import re
import sys

root = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "site")
rid, data_path, out = sys.argv[1], sys.argv[2], sys.argv[3]
read = lambda p: open(os.path.join(root, p), encoding="utf-8").read()


def manifest(r):
    t = read(f"reports/{r}/report.php")
    g = lambda k: re.search(rf"'{k}' => '([^']*)'", t).group(1)
    return {"id": r, "title": g("title"), "group": g("group"), "desc": g("desc"), "data_var": g("data_var"),
            "order": int(re.search(r"'order' => (\d+)", t).group(1)), "css": "'css' =>" in t}


reps = sorted((manifest(r) for r in os.listdir(os.path.join(root, "reports")) if os.path.isfile(os.path.join(root, "reports", r, "report.php"))),
              key=lambda m: m["order"])
cur = next(m for m in reps if m["id"] == rid)
esc = lambda s: s.replace("&", "&amp;").replace("<", "&lt;").replace('"', "&quot;")
groups = {}
for m in reps:
    groups.setdefault(m["group"], []).append(m)
items = "".join(f'<div class="gnav-group">{esc(g)}</div>' + "".join(
    f'<a role="menuitem" href="{m["id"]}_preview.html"{" aria-current=\"page\"" if m["id"] == rid else ""}><b>{esc(m["title"])}</b><span>{esc(m["desc"])}</span></a>'
    for m in lst) for g, lst in groups.items())
nav = (f'<div class="gnav" id="gnav"><button class="gnav-btn" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="gnav-menu">'
       f'<span>{esc(cur["title"])}</span><span class="gnav-car" aria-hidden="true">▾</span></button>'
       f'<div class="gnav-menu" id="gnav-menu" role="menu" hidden>{items}</div></div>')
controls = re.sub(r"^<\?php.*?\?>\s*", "", read(f"reports/{rid}/controls.php"), flags=re.S)
css = read("assets/dashboard.css") + read("assets/app.css") + (read(f"reports/{rid}/view.css") if cur["css"] else "")
data = json.dumps(json.load(open(data_path, encoding="utf-8")), ensure_ascii=False, separators=(",", ":")).replace("<", "\\u003c")
html = f"""<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(cur['title'])} - предпросмотр</title><style>{css}</style>
<script>try{{var t=localStorage.getItem('ns_dash_theme');if(t)document.documentElement.dataset.theme=JSON.parse(t);}}catch(e){{}}</script></head>
<body><header class="top"><div class="wrap"><div class="brandrow">
<div class="brand">НЕ ШКОЛА<small>Предпросмотр · не сайт</small></div>{nav}<div class="asof" id="asof"></div><div class="spacer"></div>
<div class="navlinks"><button class="btn" id="theme" type="button">Тема</button></div></div>
{controls}</div></header>
<div class="wrap"><main id="main"></main></div><div id="tip"></div>
<script>window.{cur['data_var']}={data};</script>
<script>{read('assets/nav.js')}</script>
<script>{read(f'reports/{rid}/view.js')}</script>
</body></html>"""
open(out, "w", encoding="utf-8").write(html)
print("written", out, os.path.getsize(out))
