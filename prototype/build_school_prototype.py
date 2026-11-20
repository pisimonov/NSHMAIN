#!/usr/bin/env python3
"""Статичный прототип «Экономика школы» на демо-данных: стили и меню - из site/.

    python3 build_school_prototype.py [out.html]
"""
import os
import sys

here = os.path.dirname(os.path.abspath(__file__))
site = os.path.join(here, "..", "site")
read = lambda *p: open(os.path.join(*p), encoding="utf-8").read()
out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(here, "school_prototype.html")
html = read(here, "school_template.html")
html = html.replace("/*CSS*/", read(site, "assets", "dashboard.css") + read(site, "assets", "app.css"), 1)
html = html.replace("/*NAVJS*/", read(site, "assets", "nav.js"), 1)
open(out, "w", encoding="utf-8").write(html)
print("written", out, os.path.getsize(out))
