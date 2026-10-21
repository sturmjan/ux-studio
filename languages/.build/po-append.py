"""Append translations for the entries listed by po-missing.py (dev helper).

    python languages/.build/po-append.py <translations.json>

<translations.json> is a list of Czech strings in the order of missing.json.
Each entry keeps the references/translator comments from the fresh .pot, so
`wp i18n make-json` maps JS strings to the right chunk. Placeholders
(%s, %d, %1$s...) must match, otherwise nothing is written.
"""
import json
import re
import sys
from pathlib import Path

LANG = Path(__file__).resolve().parent.parent
PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sd]')

missing = json.loads((LANG / '.build' / 'missing.json').read_text(encoding='utf-8'))
cs = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))

if len(missing) != len(cs):
    sys.exit(f'count mismatch: {len(missing)} missing vs {len(cs)} translations')


def unescape(s):
    return s.replace('\\"', '"').replace('\\\\', '\\')


def escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"')


bad = []
for entry, tr in zip(missing, cs):
    first = tr[0] if isinstance(tr, list) else tr
    if sorted(PLACEHOLDER.findall(unescape(entry['id']))) != sorted(PLACEHOLDER.findall(first)):
        bad.append((entry['id'][:80], tr[:80]))
if bad:
    for b in bad:
        print('PLACEHOLDER MISMATCH:', b)
    sys.exit(1)

blocks = []
for entry, tr in zip(missing, cs):
    lines = list(entry['comments'])
    lines += ['#: ' + r for r in entry['refs']]
    if entry['ctx'] is not None:
        lines.append('msgctxt "' + entry['ctx'] + '"')
    lines.append('msgid "' + entry['id'] + '"')
    if entry.get('plural'):
        # Plural entries: the translation is a list with one string per plural form.
        lines.append('msgid_plural "' + entry['plural'] + '"')
        for n, form in enumerate(tr):
            lines.append('msgstr[' + str(n) + '] "' + escape(form) + '"')
    else:
        lines.append('msgstr "' + escape(tr) + '"')
    blocks.append('\n'.join(lines))

po = LANG / 'ux-studio-cs_CZ.po'
text = po.read_text(encoding='utf-8').rstrip('\n')
po.write_text(text + '\n\n' + '\n\n'.join(blocks) + '\n', encoding='utf-8')
print(f'appended {len(blocks)} entries')
