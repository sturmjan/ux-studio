"""Refresh the `#:` references of existing .po entries from the fresh .pot.

    python languages/.build/po-refs.py

`wp i18n make-json` puts a string into a script's JSON only when the .po
entry references that script. A string first used in PHP and later also in
JS (a settings section label reused as a menu item) keeps its old PHP-only
references, so the SPA shows it in English. This rewrites only the reference
lines; msgstr, comments and entries missing from the .pot (meta.json
strings) stay untouched - unlike `wp i18n update-po`.
"""
import re
from pathlib import Path

LANG = Path(__file__).resolve().parent.parent
STR_RE = re.compile(r'"((?:[^"\\]|\\.)*)"')


def field(block, name):
    m = re.search(r'^' + name + r' ((?:".*"\n?)+)', block, re.M)
    return ''.join(STR_RE.findall(m.group(1))) if m else None


def key(block):
    return (field(block, 'msgctxt'), field(block, 'msgid'))


pot_refs = {}
for block in re.split(r'\n\s*\n', (LANG / 'ux-studio.pot').read_text(encoding='utf-8')):
    if field(block, 'msgid'):
        pot_refs[key(block)] = [l for l in block.split('\n') if l.startswith('#: ')]

po_path = LANG / 'ux-studio-cs_CZ.po'
blocks = re.split(r'\n\s*\n', po_path.read_text(encoding='utf-8').rstrip('\n'))
changed = 0
out = []
for block in blocks:
    k = key(block)
    if k[1] and k in pot_refs:
        lines = block.split('\n')
        old_refs = [l for l in lines if l.startswith('#: ')]
        if old_refs != pot_refs[k]:
            rest = [l for l in lines if not l.startswith('#: ')]
            # References go after translator comments (#.), before flags (#,) and msgid.
            idx = next((i for i, l in enumerate(rest) if not l.startswith('#.')), len(rest))
            block = '\n'.join(rest[:idx] + pot_refs[k] + rest[idx:])
            changed += 1
    out.append(block)

po_path.write_text('\n\n'.join(out) + '\n', encoding='utf-8')
print(f'references refreshed in {changed} entries')
