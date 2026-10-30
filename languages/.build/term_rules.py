"""English terminology rules (color, e-mail, allowlist/blocklist, central app, turn on/off) applied to one source string.
Shared by po-apply-dict.py so that dictionaries written before a rule existed still match the new msgids."""
import re

SW = {'switch': 'turn', 'switches': 'turns', 'switched': 'turned', 'switching': 'turning'}


def _sw(m):
    word = m.group(1)
    new = SW[word.lower()]
    if word[0].isupper():
        new = new[0].upper() + new[1:]
    return new + ' ' + m.group(2)


def rule(s):
    """English terminology rules applied to one translatable string (source form or msgid)."""
    s = re.sub(r'\b([Cc])olour', r'\1olor', s)
    s = re.sub(r'(?<![{@\w-])([Ee])mail(s?)\b', r'\1-mail\2', s)
    s = re.sub(r'\b([Ww])hite-?list', lambda m: ('A' if m.group(1) == 'W' else 'a') + 'llowlist', s)
    s = re.sub(r'\b([Bb])lack-?list', lambda m: m.group(1) + 'locklist', s)
    s = re.sub(r'\b([Cc])entral application', r'\1entral app', s)
    s = s.replace('Central App', 'Central app')
    s = re.sub(r'\b(Switch(?:es|ed|ing)?|switch(?:es|ed|ing)?) (on|off)\b', _sw, s)
    return s
