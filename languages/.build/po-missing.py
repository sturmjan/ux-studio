"""List pot entries that have no Czech translation in the .po (dev helper).

    python languages/.build/po-missing.py   -> languages/.build/missing.json

Unlike `wp i18n update-po` this never drops entries from the .po (module
names/descriptions from meta.json are not in the .pot - see README).
"""
import json
import re
from pathlib import Path

LANG = Path(__file__).resolve().parent.parent
STR_RE = re.compile(r'"((?:[^"\\]|\\.)*)"')


def join(chunk):
    return ''.join(STR_RE.findall(chunk))


def field(block, name):
    m = re.search(r'^' + name + r' ((?:".*"\n?)+)', block, re.M)
    return join(m.group(1)) if m else None


def parse(path):
    out = {}
    for block in re.split(r'\n\s*\n', path.read_text(encoding='utf-8')):
        msgid = field(block, 'msgid')
        if not msgid:
            continue
        lines = block.split('\n')
        out[(field(block, 'msgctxt'), msgid)] = {
            'refs': [l[3:] for l in lines if l.startswith('#: ')],
            'comments': [l for l in lines if l.startswith('#.')],
            'plural': field(block, 'msgid_plural'),
            'str': field(block, 'msgstr') or field(block, r'msgstr\[0\]'),
        }
    return out


pot = parse(LANG / 'ux-studio.pot')
po = parse(LANG / 'ux-studio-cs_CZ.po')
missing = [
    {'ctx': k[0], 'id': k[1], 'refs': v['refs'], 'comments': v['comments'], 'plural': v['plural']}
    for k, v in pot.items()
    if k not in po or not po[k]['str']
]
(LANG / '.build' / 'missing.json').write_text(json.dumps(missing, ensure_ascii=False, indent=1), encoding='utf-8')
print(len(missing))
for m in missing:
    print(m['id'][:150])
