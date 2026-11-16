#!/usr/bin/env python3
"""Сборка самодостаточных HTML-прототипов: данные + глобальное меню отчётов.

    python3 build_html.py revenue <data.js> <out.html>
    python3 build_html.py debt    <debt_data.js> <out.html>
"""
import os
import re
import sys

here = os.path.dirname(os.path.abspath(__file__))
kind, data_path, out_path = sys.argv[1], sys.argv[2], sys.argv[3]
read = lambda n: open(os.path.join(here, n), encoding="utf-8").read()
tpl = read("template.html" if kind == "revenue" else "debt_template.html")
nav = read("nav_snippet.html").replace("__CUR__", kind)
if kind == "debt":
    base_css = re.search(r"<style>\n(.*?)</style>", read("template.html"), re.S).group(1)
    tpl = tpl.replace("/*__BASECSS__*/", base_css, 1)
data = open(data_path, encoding="utf-8").read().replace("</", "<\\/")
for ph in ("/*__DATA__*/", "<!--__NAV__-->"):
    assert ph in tpl, ph
tpl = tpl.replace("<!--__NAV__-->", nav, 1).replace("/*__DATA__*/", data, 1)
open(out_path, "w", encoding="utf-8").write(tpl)
print("written", out_path, os.path.getsize(out_path), "bytes")
