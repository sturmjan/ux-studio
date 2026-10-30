"""Remove .po entries that were renamed by a terminology change (dev helper).

    python languages/.build/po-prune.py

Reads .build/term-map.json (old English => new English). An old entry is removed only when the new one is in the
.po and the old text no longer appears anywhere in includes/ or src/ (so a string that is still used stays).
`wp i18n update-po` is NOT used (README: it drops the meta.json strings), so obsolete entries do not go away by
themselves.
"""
import json
import re
from pathlib import Path

LANG = Path(__file__).resolve().parent.parent
ROOT = LANG.parent
STR_RE = re.compile(r'"((?:[^"\\]|\\.)*)"')


def unesc(s):
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '"': '"', '\\': '\\'}.get(m.group(1), m.group(1)), s)


def field(block, name):
    m = re.search(r'^' + name + r' ((?:".*"\n?)+)', block, re.M)
    return ''.join(STR_RE.findall(m.group(1))) if m else None


termmap = json.loads((LANG / '.build' / 'term-map.json').read_text(encoding='utf-8'))
source = []
for base in ('includes', 'src'):
    for p in (ROOT / base).rglob('*'):
        if p.is_file() and p.suffix in ('.php', '.tsx', '.ts', '.json'):
            source.append(p.read_text(encoding='utf-8', errors='replace'))
source = '\n'.join(source)

po_path = LANG / 'ux-studio-cs_CZ.po'
blocks = re.split(r'\n\s*\n', po_path.read_text(encoding='utf-8').rstrip('\n'))
ids = {unesc(field(b, 'msgid')) for b in blocks if field(b, 'msgid') and field(b, 'msgctxt') is None}
keep = []
removed = 0
for b in blocks:
    mid = field(b, 'msgid')
    if mid and field(b, 'msgctxt') is None:
        old = unesc(mid)
        new = termmap.get(old)
        if new and new != old and new in ids and old not in source and old.replace("'", "\\'") not in source and old.replace('"', '\\"') not in source:
            removed += 1
            continue
    keep.append(b)
po_path.write_text('\n\n'.join(keep) + '\n', encoding='utf-8')
print('removed %d renamed entries' % removed)
