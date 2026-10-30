"""Replace the Czech text of EXISTING .po entries (dev helper; po-append.py only adds new ones).

    python languages/.build/po-set.py <dict.json>

<dict.json> maps an English msgid to the new Czech text, or for a plural entry to a list with one text per form
(or to {"msgid_plural": "...", "msgstr": [...]} to turn a singular entry into a plural one).
Entries that are not in the .po are reported and ignored. Placeholders must still match the msgid.
"""
import json
import re
import sys
from pathlib import Path

LANG = Path(__file__).resolve().parent.parent
STR_RE = re.compile(r'"((?:[^"\\]|\\.)*)"')
PLACEHOLDER = re.compile(r'%(?:\d+\$)?[sd]')


def unesc(s):
    return re.sub(r'\\(.)', lambda m: {'n': '\n', 't': '\t', '"': '"', '\\': '\\'}.get(m.group(1), m.group(1)), s)


def esc(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n').replace('\t', '\\t')


def field(block, name):
    m = re.search(r'^' + name + r' ((?:".*"\n?)+)', block, re.M)
    return ''.join(STR_RE.findall(m.group(1))) if m else None


changes = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
po_path = LANG / 'ux-studio-cs_CZ.po'
blocks = re.split(r'\n\s*\n', po_path.read_text(encoding='utf-8').rstrip('\n'))
done = set()
for i, block in enumerate(blocks):
    mid = field(block, 'msgid')
    if not mid or field(block, 'msgctxt') is not None:
        continue
    key = unesc(mid)
    if key not in changes:
        continue
    new = changes[key]
    plural_id = None
    if isinstance(new, dict):  # {"msgid_plural": "...", "msgstr": [...]} also turns a singular entry into a plural one
        plural_id = new['msgid_plural']
        new = new['msgstr']
    forms = new if isinstance(new, list) else [new]
    if sorted(PLACEHOLDER.findall(key)) != sorted(PLACEHOLDER.findall(forms[0])):
        sys.exit('placeholder mismatch: ' + key[:80])
    lines = block.split('\n')
    head = []
    for ln in lines:
        if ln.startswith('msgstr'):
            break
        head.append(ln)
    # keep every line up to the first msgstr (comments, refs, msgid, msgid_plural, continuation lines)
    if plural_id is not None and field(block, 'msgid_plural') is None:
        head.append('msgid_plural "%s"' % esc(plural_id))
    if isinstance(new, list):
        tail = ['msgstr[%d] "%s"' % (n, esc(f)) for n, f in enumerate(forms)]
    else:
        tail = ['msgstr "%s"' % esc(forms[0])]
    blocks[i] = '\n'.join(head + tail)
    done.add(key)
for key in changes:
    if key not in done:
        print('not in .po: ' + key[:100])
po_path.write_text('\n\n'.join(blocks) + '\n', encoding='utf-8')
print('changed %d entries' % len(done))
