#!/usr/bin/env python3
"""Данные для прототипа отчёта «Задолженность партнёров по роялти».

    python3 build_debt_data.py <reg.csv> <revenue_month.csv> <out.js> --as-of 2026-11-16 --modified 2026-11-16T17:22

reg.csv - лист «Реестр задолженности СМОТРЕТЬ СЮДА» (B Партнер, C Город, D Направление, E Месяц начисления,
F Тип, G Сумма начисления, H Задолженность, I Комментарий, J Месяц оплаты, K Сумма оплаты, L Месяц списания,
M Списание, N Курсовая разница, O Ручная корректировка).
revenue_month.csv - raw_data_income последнего месяца (для статуса школы «Закрыта» и страны).
"""
import argparse
import csv
import json
import re
from collections import defaultdict

FOREIGN_EXTRA = {"Валенсия": "Испания"}


def num(x):
    try:
        return float(str(x).replace("\xa0", "").replace(" ", "").replace(",", ".") or 0)
    except ValueError:
        return 0.0


def mi(s):
    m = re.match(r"^(\d{4})_(\d{1,2})$", (s or "").strip())
    return int(m.group(1)) * 12 + int(m.group(2)) - 1 if m else None


def norm_dir(d):
    d = re.sub(r"\s+", " ", d or "").strip()
    return "Гитара и Вокал" if d.lower() == "гитара и вокал" else d


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("reg"); ap.add_argument("revenue"); ap.add_argument("out")
    ap.add_argument("--as-of", required=True); ap.add_argument("--modified", default="")
    a = ap.parse_args()

    # страна и статус школ из отчёта по выручке
    country, rev = {}, defaultdict(dict)
    for r in list(csv.reader(open(a.revenue, encoding="utf-8")))[1:]:
        city = re.sub(r",.*", "", r[1]).strip()
        country[city] = r[0].strip()
        d = r[3].strip().replace("Барабны", "Барабаны")
        rev[(city, d)][re.sub(r"\s+", "", r[10])] = bool(r[12].strip())
    country.update(FOREIGN_EXTRA)

    def school_status(city, d):
        m = re.match(r"^(.*?)\s+(\d+)$", city)
        base, n = (m.group(1), int(m.group(2))) if m else (city, 1)
        sch = rev.get((base, d))
        if not sch:
            return "нет данных"
        name = f"{base}-{d.replace('Барабаны', 'Барабны')}-{n}".replace(" ", "")
        if name in sch:
            return "закрыта" if sch[name] else "работает"
        return "закрыта" if all(sch.values()) else "работает"

    rows_in = list(csv.reader(open(a.reg, encoding="utf-8")))[1:]
    schools, sidx, rows, comments, cidx = [], {}, [], [], {}
    other = defaultdict(float)
    skipped = 0
    for r in rows_in:
        r = r + [""] * (21 - len(r))
        typ = r[5].strip()
        if typ != "Роялти":
            if typ:
                other[typ] += num(r[7])
            continue
        m = mi(r[4])
        if m is None:
            skipped += 1
            continue
        city, d = re.sub(r"\s+", " ", r[2]).strip(), norm_dir(r[3])
        key = (city, d)
        if key not in sidx:
            base = re.sub(r"\s+\d+$", "", city)
            co = country.get(base, "Россия")
            sidx[key] = len(schools)
            schools.append({"c": city, "d": d, "p": "", "pm": -1, "co": co, "reg": "ru" if co == "Россия" else "foreign",
                            "st": school_status(city, d)})
        s = sidx[key]
        partner = re.sub(r"\s+", " ", r[1]).strip()
        if partner and m >= schools[s]["pm"]:
            schools[s]["p"], schools[s]["pm"] = partner, m
        cm = re.sub(r"\s+", " ", r[8]).strip()
        if cm and cm.lower() != "курсовая разница":
            if cm not in cidx:
                cidx[cm] = len(comments); comments.append(cm)
            ci = cidx[cm]
        else:
            ci = -1
        rows.append([s, m, round(num(r[6]), 2), round(num(r[7]), 2), round(num(r[10]), 2), mi(r[9]),
                     round(num(r[12]), 2), ci])
    for sc in schools:
        sc.pop("pm", None)
    y, mo = int(a.as_of[:4]), int(a.as_of[5:7])
    payload = {
        "meta": {"asOf": a.as_of, "curMi": y * 12 + mo - 1, "modified": a.modified, "tol": 100,
                 "otherTypesDebt": {k: round(v) for k, v in other.items() if abs(v) > 0.5}, "skipped": skipped,
                 "source": "Реестр задолженности Не школа / лист «Реестр задолженности СМОТРЕТЬ СЮДА»"},
        "schools": schools, "rows": rows, "comments": comments,
    }
    with open(a.out, "w", encoding="utf-8") as f:
        f.write("window.DEBT_DATA=")
        json.dump(payload, f, ensure_ascii=False, separators=(",", ":"))
        f.write(";")
    print("schools", len(schools), "rows", len(rows), "comments", len(comments), "other", payload["meta"]["otherTypesDebt"])


if __name__ == "__main__":
    main()
