#!/bin/sh
#
# davServices — Referenz-RFCs herunterladen
# ------------------------------------------------------------------
# Holt die maßgeblichen Textfassungen aller Spezifikationen, auf denen
# davServices beruht. Die Textfassung ist die verbindliche; PDF- und
# HTML-Fassungen sind Bequemlichkeiten.
#
# Benötigt nur curl. Bereits vorhandene Dateien werden übersprungen.
# Ablage: ./rfc/rfc<nummer>.txt

set -eu

BASE="https://www.rfc-editor.org/rfc"
DIR="$(cd "$(dirname "$0")" && pwd)/rfc"

mkdir -p "$DIR"

ok=0
skip=0
fail=0

# Zeilenweise gelesen, damit Beschreibungen Leerzeichen enthalten dürfen.
while IFS='|' read -r num desc; do
    [ -n "${num:-}" ] || continue
    case "$num" in \#*) continue ;; esac

    target="$DIR/rfc$num.txt"

    if [ -f "$target" ]; then
        printf '  --   rfc%-5s %s (bereits vorhanden)\n' "$num" "$desc"
        skip=$((skip + 1))
        continue
    fi

    if curl -fsS --retry 2 -o "$target" "$BASE/rfc$num.txt"; then
        printf '  OK   rfc%-5s %s\n' "$num" "$desc"
        ok=$((ok + 1))
    else
        printf '  FEHL rfc%-5s %s\n' "$num" "$desc"
        rm -f "$target"
        fail=$((fail + 1))
    fi
done <<'LIST'
2119|Schluesselwoerter fuer Anforderungsgrade
8174|Praezisierung der Schluesselwoerter aus RFC 2119
4918|WebDAV-Kern
3744|WebDAV Access Control
4791|CalDAV
6352|CardDAV
5545|iCalendar
5546|iTIP
6047|iMIP
6350|vCard 4.0
2426|vCard 3.0
2425|MIME Directory Profile
6578|Collection Synchronization
6764|Diensterkennung
8615|Well-Known URIs
4331|Quota and Size Properties
5689|Extended MKCOL
4790|Collation Registry
5051|i;unicode-casemap
6638|CalDAV Scheduling
7986|Neue iCalendar-Properties
6238|TOTP
7617|HTTP Basic Authentication
9110|HTTP Semantics
9111|HTTP Caching
9112|HTTP/1.1
LIST

printf '\n%d geladen, %d übersprungen, %d fehlgeschlagen.\nAblage: %s\n' \
    "$ok" "$skip" "$fail" "$DIR"

if [ "$fail" -gt 0 ]; then
    printf '\nSchlaegt der Abruf durchgaengig fehl, blockiert vermutlich eine\n'
    printf 'Firewall oder ein Proxy den Zugriff auf rfc-editor.org.\n'
    exit 1
fi
