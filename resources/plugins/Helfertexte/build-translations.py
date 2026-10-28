#!/usr/bin/env python3
"""
Erzeugt lang/<locale>.po dieses Plugins, das alle Texte mit "Engel"/"angel"
durch "Helfer"/"helper" ersetzt. Plugin-Übersetzungen werden nach denen des
Originals geladen und überschreiben sie, ohne dass Dateien des Originals
(upstream) verändert werden müssen.

Nach jedem Merge von upstream erneut ausführen:
    python3 resources/plugins/Helfertexte/build-translations.py
"""

import re
import sys
from pathlib import Path

PLUGIN = Path(__file__).resolve().parent
ROOT = PLUGIN.parent.parent.parent
LANG_DIR = ROOT / 'resources' / 'lang'
OUT_DIR = PLUGIN / 'lang'
SOURCES = ['default.po', 'additional.po']

# Einträge, die unverändert bleiben (Credits an die Original-Autoren)
SKIP_IDS = {'credits.credit'}

# Teile, die nie verändert werden: URLs, Platzhalter, HTML-Tags
PROTECT = re.compile(r'https?://[^\s)"\\]+|\{[^}]*\}|<[^>]+>')

NOT_LETTER = r'(?<![A-Za-zÄÖÜäöüß])'

RULES = {
    'de_DE': [
        (re.compile(NOT_LETTER + r'Engel'), 'Helfer'),
        (re.compile(NOT_LETTER + r'engelsystem'), 'helfersystem'),
    ],
    'en_US': [
        (re.compile(NOT_LETTER + r'An angel'), 'A helper'),
        (re.compile(NOT_LETTER + r'an angel'), 'a helper'),
        (re.compile(NOT_LETTER + r'ANGEL'), 'HELPER'),
        (re.compile(NOT_LETTER + r'Angel'), 'Helper'),
        (re.compile(NOT_LETTER + r'angel'), 'helper'),
        (re.compile(NOT_LETTER + r'Engelsystem'), 'Helfersystem'),
        (re.compile(NOT_LETTER + r'engelsystem'), 'helfersystem'),
    ],
}

# Übersetzungs-Schlüssel wie "angeltypes.about" sind keine Anzeigetexte
KEY_LIKE = re.compile(r'^[a-z0-9_.-]+$')


def parse_po(path):
    """Minimaler PO-Parser; Strings bleiben in escapter Form."""
    entries, entry, field = [], {}, None
    for line in path.read_text(encoding='utf-8').splitlines() + ['']:
        line = line.strip()
        if not line or line.startswith('#'):
            if 'msgid' in entry:
                entries.append(entry)
                entry, field = {}, None
            continue
        m = re.match(r'^(msgid|msgid_plural|msgstr(?:\[\d+])?)\s+"(.*)"$', line)
        if m:
            field = m.group(1)
            entry[field] = m.group(2)
        elif line.startswith('"') and field:
            entry[field] += line[1:-1]
    return entries


def replace(text, rules):
    parts, last = [], 0
    for m in PROTECT.finditer(text):
        parts.append((text[last:m.start()], True))
        parts.append((m.group(0), False))
        last = m.end()
    parts.append((text[last:], True))

    out = []
    for part, editable in parts:
        if editable:
            for pattern, repl in rules:
                part = pattern.sub(repl, part)
        out.append(part)
    return ''.join(out)


def build(locale, all_ids):
    rules = RULES[locale]
    translated = {}
    for name in SOURCES:
        path = LANG_DIR / locale / name
        if path.exists():
            for e in parse_po(path):
                if e['msgid']:
                    translated[e['msgid']] = e

    result = {}
    for msgid, e in translated.items():
        if msgid in SKIP_IDS:
            continue
        new = {k: (replace(v, rules) if k.startswith('msgstr') else v) for k, v in e.items()}
        if new != e:
            result[msgid] = new

    # Ohne Übersetzung zeigt das System die msgid selbst an (englische Quelltexte)
    if locale == 'en_US':
        for msgid, e in all_ids.items():
            if msgid in translated or msgid in SKIP_IDS or KEY_LIKE.match(msgid):
                continue
            new_id = replace(msgid, rules)
            if new_id == msgid:
                continue
            new = {'msgid': msgid}
            if 'msgid_plural' in e:
                new['msgid_plural'] = e['msgid_plural']
                new['msgstr[0]'] = new_id
                new['msgstr[1]'] = replace(e['msgid_plural'], rules)
            else:
                new['msgstr'] = new_id
            result[msgid] = new

    return result


def write(locale, entries):
    out = OUT_DIR / (locale + '.po')
    out.parent.mkdir(parents=True, exist_ok=True)
    lines = [
        '# Automatisch erzeugt von build-translations.py - nicht von Hand bearbeiten.',
        '# Eigene zusätzliche Anpassungen gehören in manual/' + locale + '.po',
        'msgid ""',
        'msgstr ""',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Language: ' + locale + '\\n"',
    ]
    plural = '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"'
    lines.append(plural)
    lines.append('')

    # Manuelle Ergänzungen ersetzen automatische Einträge mit gleicher msgid
    manual = PLUGIN / 'manual' / (locale + '.po')
    if manual.exists():
        for e in parse_po(manual):
            entries.pop(e['msgid'], None)

    for msgid in sorted(entries):
        e = entries[msgid]
        for key in ['msgid', 'msgid_plural', 'msgstr', 'msgstr[0]', 'msgstr[1]']:
            if key in e:
                lines.append(f'{key} "{e[key]}"')
        lines.append('')

    if manual.exists():
        lines.append('# --- Manuelle Ergänzungen aus manual/' + locale + '.po ---')
        lines.append(manual.read_text(encoding='utf-8'))

    out.write_text('\n'.join(lines), encoding='utf-8')
    return out


def main():
    all_ids = {}
    for name in SOURCES:
        path = LANG_DIR / 'de_DE' / name
        for e in parse_po(path):
            if e['msgid']:
                all_ids[e['msgid']] = e

    for locale in RULES:
        entries = build(locale, all_ids)
        out = write(locale, entries)
        print(f'{out.relative_to(ROOT)}: {len(entries)} Einträge')


if __name__ == '__main__':
    sys.exit(main())
