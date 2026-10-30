"""Translate the entries listed by po-missing.py from dictionaries of Czech strings (dev helper).

    python languages/.build/po-missing.py                 # -> .build/missing.json
    python languages/.build/po-apply-dict.py <dict.json>...   # writes .build/my-cs.json, and appends via po-append.py

Each <dict.json> maps an English source string to its Czech text (later files win). A plural entry is
    "%d entry": { "msgid_plural": "%d entries", "msgstr": [ "one", "few", "many" ] }
Besides the strings of the .pot, the names, descriptions and "how" texts of every includes/Modules/*/meta.json
are added when the .po has no entry for them (make-pot cannot see them, see README).

A string that is not in any dictionary but only differs from an existing .po entry by the terminology rules of
.build/term-map.json (old English => new English) takes the Czech text of that old entry.
Nothing is written when any string stays untranslated; the list of those is printed instead.
"""
import json
import re
import subprocess
import sys
from pathlib import Path

LANG = Path(__file__).resolve().parent.parent
ROOT = LANG.parent
STR_RE = re.compile(r'"((?:[^"\\]|\\.)*)"')


def unesc(s):
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '"': '"', '\\': '\\'}.get(m.group(1), m.group(1)), s)


def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def field(block, name):
    m = re.search(r'^' + name + r' ((?:".*"\n?)+)', block, re.M)
    return ''.join(STR_RE.findall(m.group(1))) if m else None


def read_po():
    """(msgctxt, msgid) => Czech text; for plural entries a list with one text per form."""
    out = {}
    for block in re.split(r'\n\s*\n', (LANG / 'ux-studio-cs_CZ.po').read_text(encoding='utf-8')):
        mid = field(block, 'msgid')
        if not mid:
            continue
        key = (field(block, 'msgctxt'), unesc(mid))
        if field(block, 'msgid_plural') is not None:
            forms = [unesc(field(block, r'msgstr\[%d\]' % n) or '') for n in range(3)]
            out[key] = forms if forms[0] else ''
        else:
            out[key] = unesc(field(block, 'msgstr') or '')
    return out


sys.path.insert(0, str(LANG / '.build'))
from term_rules import rule  # noqa: E402

dicts = {}
for f in sys.argv[1:]:
    dicts.update(json.loads(Path(f).read_text(encoding='utf-8')))
# A dictionary written before the terminology rules existed still matches the new msgids.
for k in list(dicts):
    dicts.setdefault(rule(k), dicts[k])

po = read_po()
termmap_path = LANG / '.build' / 'term-map.json'
termmap = json.loads(termmap_path.read_text(encoding='utf-8')) if termmap_path.exists() else {}
reverse = {}
for old, new in termmap.items():
    reverse.setdefault(new, old)

missing = json.loads((LANG / '.build' / 'missing.json').read_text(encoding='utf-8'))
known = {(m['ctx'], unesc(m['id'])) for m in missing}

# meta.json strings are not in the .pot
for meta in sorted((ROOT / 'includes' / 'Modules').glob('*/meta.json')):
    j = json.loads(meta.read_text(encoding='utf-8'))
    for key in ('name', 'description', 'how'):
        v = j.get(key)
        if isinstance(v, str) and v and (None, v) not in po and (None, v) not in known:
            missing.append({'ctx': None, 'id': esc(v), 'refs': [str(meta.relative_to(ROOT)).replace('\\', '/') + ':1'], 'comments': [], 'plural': None})
            known.add((None, v))

result = []
unresolved = []
for m in missing:
    mid = unesc(m['id'])
    tr = dicts.get(mid)
    if tr is None:
        old = reverse.get(mid)
        if old is not None and (m['ctx'], old) in po and po[(m['ctx'], old)]:
            tr = po[(m['ctx'], old)]
    if tr is None:
        unresolved.append(mid)
        result.append(None)
        continue
    if m.get('plural'):
        if isinstance(tr, dict):
            tr = tr['msgstr']
        elif not isinstance(tr, list):
            unresolved.append(mid + '  [plural needs a list]')
    result.append(tr)

(LANG / '.build' / 'missing.json').write_text(json.dumps(missing, ensure_ascii=False, indent=1), encoding='utf-8')
if unresolved:
    print(f'{len(unresolved)} strings without a translation:')
    for u in unresolved:
        print('  ' + u[:160].replace('\n', '\\n'))
    sys.exit(1)
(LANG / '.build' / 'my-cs.json').write_text(json.dumps(result, ensure_ascii=False, indent=1), encoding='utf-8')
print(f'{len(result)} translations ready')
sys.exit(subprocess.call([sys.executable, str(LANG / '.build' / 'po-append.py'), str(LANG / '.build' / 'my-cs.json')]))
