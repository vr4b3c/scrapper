#!/usr/bin/env python3
import csv, re, sys
from collections import defaultdict
path = '/home/vrabec/Projects/scrapper/scrapery2026/zoznam.sk/files/zoznam_sk.csv'
rows = []
with open(path, newline='', encoding='utf-8') as f:
    reader = csv.DictReader(f, delimiter=';')
    for r in reader:
        rows.append(r)

total = len(rows)
# Exact duplicate rows (stringified)
seen = defaultdict(list)
for i,r in enumerate(rows, start=2):
    key = tuple((k, v.strip() if v is not None else '') for k,v in r.items())
    seen[key].append(i)
exact_dups = {k:v for k,v in seen.items() if len(v)>1}

# Duplicate by Název
name_map = defaultdict(list)
for i,r in enumerate(rows, start=2):
    name = (r.get('Název') or '').strip().lower()
    if name:
        name_map[name].append(i)
name_dups = {n:idxs for n,idxs in name_map.items() if len(idxs)>1}

# Duplicate by E-mail (non-empty)
email_map = defaultdict(list)
for i,r in enumerate(rows, start=2):
    email = (r.get('E-mail') or '').strip().lower()
    if email:
        email_map[email].append(i)
email_dups = {e:idxs for e,idxs in email_map.items() if len(idxs)>1}

# Duplicate by phone (normalize numbers)
phone_map = defaultdict(list)

def normalize_phone(p):
    p = p.strip()
    if not p:
        return ''
    # split on ' | ' or '|' or ','
    parts = re.split(r'\s*\|\s*|,', p)
    out = []
    for part in parts:
        part = part.strip()
        if not part:
            continue
        # keep leading +, remove other nondigits
        m = re.match(r'^(\+)?(.*)$', part)
        sign = m.group(1) or ''
        digits = re.sub(r'[^0-9]', '', m.group(2))
        if not digits:
            continue
        out.append(sign + digits)
    return out

for i,r in enumerate(rows, start=2):
    phones_field = r.get('Telefon') or ''
    phones = normalize_phone(phones_field)
    for ph in phones:
        phone_map[ph].append(i)
phone_dups = {p:idxs for p,idxs in phone_map.items() if len(idxs)>1}

# Build report
out = []
out.append(f'Total records (excluding header): {total}')
out.append(f'Exact duplicate ROW groups: {len(exact_dups)}')
if exact_dups:
    out.append('First 20 exact duplicate groups (row numbers):')
    cnt=0
    for k,v in exact_dups.items():
        out.append(f'  rows: {v}')
        cnt+=1
        if cnt>=20: break

out.append(f'Duplicate Názvů (name) groups: {len(name_dups)}')
if name_dups:
    out.append('First 20 name duplicates:')
    for i,(n,idxs) in enumerate(name_dups.items()):
        out.append(f'  "{n}" -> rows {idxs[:10]}')
        if i>=19: break

out.append(f'Duplicate E-mailů groups (non-empty): {len(email_dups)}')
if email_dups:
    out.append('First 20 email duplicates:')
    for i,(e,idxs) in enumerate(email_dups.items()):
        out.append(f'  "{e}" -> rows {idxs[:10]}')
        if i>=19: break

out.append(f'Duplicate PHONE numbers (normalized) groups: {len(phone_dups)}')
if phone_dups:
    out.append('First 20 phone duplicates:')
    for i,(p,idxs) in enumerate(phone_dups.items()):
        out.append(f'  "{p}" -> rows {idxs[:10]}')
        if i>=19: break

# Summary uniques
unique_names = len(name_map)
unique_emails = len([e for e in email_map.keys()])
unique_phones = len(phone_map)
out.append(f'Unique non-empty names: {unique_names}')
out.append(f'Unique non-empty emails: {unique_emails}')
out.append(f'Unique normalized phones: {unique_phones}')

print('\n'.join(out))
