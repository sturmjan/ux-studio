"""Objective parity check: every legacy UX1 settings field id must exist as a
settings key (or be remapped in Migrator) in the matching UX Studio module."""
import os, re, json, glob, sys
base = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
L = os.path.join(base, 'ux1-wordpress-customizer', 'modules')
S = os.path.join(base, 'ux-studio', 'includes', 'Modules')
sid = {}
for m in glob.glob(os.path.join(S, '*', 'meta.json')):
    try:
        sid[json.load(open(m, encoding='utf-8'))['id']] = os.path.dirname(m)
    except Exception:
        pass
mig = ''
for p in glob.glob(os.path.join(base, 'ux-studio', 'includes', '**', 'Migrator*.php'), recursive=True):
    mig += open(p, encoding='utf-8', errors='ignore').read()
def read(p):
    return open(p, encoding='utf-8', errors='ignore').read()
out = {}
for d in sorted(os.listdir(L)):
    lp = os.path.join(L, d)
    if not os.path.isdir(lp):
        continue
    lk = set()
    for p in glob.glob(os.path.join(lp, '**', '*.php'), recursive=True):
        t = read(p)
        if 'getSettingsFields' in t:
            lk |= set(re.findall(r"'id'\s*=>\s*'([a-z0-9_\-]+)'", t))
    lk -= {'settings', 'general'}
    if not lk:
        continue
    sd = sid.get(d)
    if not sd:
        out[d] = ('no studio module', sorted(lk))
        continue
    st = ''.join(read(p) for p in glob.glob(os.path.join(sd, '**', '*.php'), recursive=True))
    sk = set(re.findall(r"'key'\s*=>\s*'([a-z0-9_\-]+)'", st)) | set(re.findall(r"get\(\s*'([a-z0-9_\-]+)'", st))
    miss = sorted(k for k in lk if k not in sk and k.replace('-', '_') not in sk and ("'%s'" % k) not in mig)
    if miss:
        out[d] = (len(lk), miss)
for d, (n, m) in out.items():
    print(f'{d} ({n}): {", ".join(m)}')
print(f'\n{len(out)} modules with legacy settings keys missing in UX Studio')
