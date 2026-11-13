#!/usr/bin/env python3
"""Сборка данных для HTML-прототипа из CSV-выгрузок листа raw_data_income.

Использование:
    python3 build_data.py <папка_с_csv> <выходной_data.js> --as-of 2026-11-12

Файлы в папке называются YYYY-MM.csv (например 2026-11.csv).
Ключевые столбцы (по ТЗ): K = Школа (id филиала), H = Выручка (рубли).
"""
import argparse
import csv
import json
import os
import re
from collections import defaultdict

COL_COUNTRY, COL_CITY, COL_DIR, COL_DATE, COL_REV, COL_SCHOOL, COL_PARTNER, COL_CLOSED = 0, 1, 3, 5, 7, 10, 11, 12

OWN_NETWORK = [
    "Москва-Барабны-1",
    "Санкт-Петербург -Барабны-1",
    "Казань-Барабны-1",
    "Тюмень-Кидс-2",          # заменяет закрытую Тюмень-Кидс-1 (решение от 13.11.2026)
    "Владикавказ-Кидс-1",
]


def num(x: str) -> float:
    x = (x or "").replace("\xa0", "").replace(" ", "").replace(",", ".")
    try:
        return float(x)
    except ValueError:
        return 0.0


def norm_city(city: str, country: str) -> str:
    c = re.sub(r"\s+", " ", city or "").strip()
    # "Астана, Казахстан" -> "Астана" (суффикс страны дублирует столбец Страна)
    suffix = ", " + (country or "").strip()
    if country and c.endswith(suffix):
        c = c[: -len(suffix)].strip()
    return c


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("src")
    ap.add_argument("out")
    ap.add_argument("--as-of", required=True, help="последний закрытый день текущего месяца, YYYY-MM-DD")
    ap.add_argument("--today", required=True, help="дата выгрузки (день идёт), YYYY-MM-DD")
    a = ap.parse_args()

    cur = a.as_of[:7]
    months = sorted(f[:7] for f in os.listdir(a.src) if re.fullmatch(r"\d{4}-\d{2}\.csv", f))
    schools = {}          # name -> meta
    order = []
    month_days = {}
    daily = {}            # month -> name -> {day: rev}
    quality = defaultdict(list)

    for m in months:
        rows = list(csv.reader(open(os.path.join(a.src, m + ".csv"), encoding="utf-8")))[1:]
        per = defaultdict(lambda: defaultdict(float))
        seen = defaultdict(int)
        maxday = 0
        for r in rows:
            name = r[COL_SCHOOL].strip()
            if not name:
                continue
            day = int(r[COL_DATE][8:10])
            maxday = max(maxday, day)
            seen[(name, day)] += 1
            per[name][day] += num(r[COL_REV])
            meta = {
                "n": name,
                "c": norm_city(r[COL_CITY], r[COL_COUNTRY]),
                "co": r[COL_COUNTRY].strip(),
                "d": r[COL_DIR].strip(),
                "p": re.sub(r"\s+", " ", r[COL_PARTNER]).strip(),
                "cl": bool(r[COL_CLOSED].strip()),
            }
            if name not in schools:
                order.append(name)
                schools[name] = meta
            elif m >= schools[name].get("_m", ""):
                meta["_m"] = m
                schools[name] = meta   # атрибуты берём из самого свежего месяца
            schools[name]["_m"] = max(m, schools[name].get("_m", ""))
        dups = sorted({n for (n, d), k in seen.items() if k > 1})
        if dups:
            quality["dups"].append({"m": m, "schools": dups})
        month_days[m] = maxday
        daily[m] = per

    idx = {n: i for i, n in enumerate(order)}
    out_schools = []
    for n in order:
        s = dict(schools[n])
        s.pop("_m", None)
        s["own"] = n in OWN_NETWORK
        out_schools.append(s)

    # Помесячные итоги по каждой школе (для динамики за 12 мес.)
    monthly = {}
    for m in months:
        arr = [None] * len(order)
        for n, days in daily[m].items():
            arr[idx[n]] = round(sum(days.values()), 2)
        monthly[m] = arr

    # Подневные ряды: текущий месяц, предыдущий, такой же год назад
    y, mo = int(cur[:4]), int(cur[5:7])
    pm = f"{y - 1}-12" if mo == 1 else f"{y}-{mo - 1:02d}"
    ly = f"{y - 1}-{mo:02d}"
    dd = {}
    for m in (cur, pm, ly):
        if m not in daily:
            continue
        nd = month_days[m]
        arr = [None] * len(order)
        for n, days in daily[m].items():
            arr[idx[n]] = [round(days.get(d, 0), 2) for d in range(1, nd + 1)]
        dd[m] = arr

    missing_own = [n for n in OWN_NETWORK if n not in idx]
    if missing_own:
        quality["own_missing"] = missing_own

    payload = {
        "meta": {
            "asOf": a.as_of,
            "today": a.today,
            "cur": cur, "pm": pm, "ly": ly,
            "months": months,
            "monthDays": month_days,
            "own": OWN_NETWORK,
        },
        "schools": out_schools,
        "daily": dd,
        "monthly": monthly,
        "quality": quality,
    }
    with open(a.out, "w", encoding="utf-8") as f:
        f.write("window.DASH_DATA=")
        json.dump(payload, f, ensure_ascii=False, separators=(",", ":"))
        f.write(";")
    print("schools", len(order), "months", months, "out bytes", os.path.getsize(a.out))


if __name__ == "__main__":
    main()
