#!/usr/bin/env python3
"""Вставляет data.js в template.html и пишет один самодостаточный HTML-файл.

    python3 build_html.py <data.js> <out.html>
"""
import os
import sys

here = os.path.dirname(os.path.abspath(__file__))
data_path, out_path = sys.argv[1], sys.argv[2]
tpl = open(os.path.join(here, "template.html"), encoding="utf-8").read()
data = open(data_path, encoding="utf-8").read().replace("</", "<\\/")
assert "/*__DATA__*/" in tpl
open(out_path, "w", encoding="utf-8").write(tpl.replace("/*__DATA__*/", data, 1))
print("written", out_path, os.path.getsize(out_path), "bytes")
