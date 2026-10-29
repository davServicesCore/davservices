#!/bin/sh
#
# davServices — Herstellerschriften herunterladen
# ------------------------------------------------------------------
# Manches, was Clients sprechen, hat keinen RFC. Die maßgebliche Fassung ist
# dann die Schrift des Herstellers, und sie gehört genauso vorgelegt wie ein
# RFC: entschieden wird am Wortlaut, nicht am Gedächtnis.
#
# Geholt wird, was ein umgesetztes Häppchen braucht — nicht der ganze Ordner
# auf Vorrat. Apple führt elf solche Schriften in
# ccs-calendarserver/doc/Extensions/; die weiteren kommen hinzu, wenn ihr
# Häppchen kommt (caldav-sharing für P7-05, caldav-notifications für P7-06).
#
# Benötigt nur curl. Bereits vorhandene Dateien werden übersprungen.
# Ablage: ./extensions/<name>.txt

set -eu

DIR="$(cd "$(dirname "$0")" && pwd)/extensions"

mkdir -p "$DIR"

ok=0
skip=0
fail=0

# Zeilenweise gelesen: Name, URL, Beschreibung.
while IFS='|' read -r name url desc; do
    [ -n "${name:-}" ] || continue
    case "$name" in \#*) continue ;; esac

    target="$DIR/$name.txt"

    if [ -f "$target" ]; then
        printf '  --   %-20s %s (bereits vorhanden)\n' "$name" "$desc"
        skip=$((skip + 1))
        continue
    fi

    if curl -fsSL --retry 2 -o "$target" "$url"; then
        printf '  OK   %-20s %s\n' "$name" "$desc"
        ok=$((ok + 1))
    else
        printf '  FEHL %-20s %s\n' "$name" "$desc"
        rm -f "$target"
        fail=$((fail + 1))
    fi
done <<'LIST'
caldav-ctag|https://raw.githubusercontent.com/apple/ccs-calendarserver/master/doc/Extensions/caldav-ctag.txt|CS:getctag (R-SYNC-06) — hebt sich selbst zugunsten von RFC 6578 auf
LIST

printf '\n%d geladen, %d übersprungen, %d fehlgeschlagen.\nAblage: %s\n' \
    "$ok" "$skip" "$fail" "$DIR"

if [ "$fail" -gt 0 ]; then
    printf '\nSchlaegt der Abruf fehl, ist die Schrift vermutlich verschoben\n'
    printf 'worden. Sie liegt im Projekt apple/ccs-calendarserver unter\n'
    printf 'doc/Extensions/ — der Pfad in der Liste oben ist dann zu berichtigen.\n'
    exit 1
fi
