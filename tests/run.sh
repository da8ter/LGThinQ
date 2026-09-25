#!/usr/bin/env bash
# Prüfstand LG ThinQ — alle Prüfungen ohne Symcon, ohne Netz, ohne LG-Konto.
#
#   tests/run.sh            Syntax, JSON, alle Testdateien; offene Befunde brechen nicht ab
#   tests/run.sh --streng   zusätzlich Exit 1, solange ein Befund des Reviews offen ist
#
# Bausteine: tests/sdk (Symcon-Kern im Speicher, Verhalten von Symcon 9.1 gemessen am 25.09.2026),
# tests/fake (LG-Cloud mit MQTT, Transport-Ersatz), tests/fixtures (LG-Beispiele aus der aktuellen
# Spezifikation, echte Klimaanlage, Ländertabelle des LG-SDK). LG-Beispiele auffrischen:
#   php tests/tools/lg_spec_holen.php   (danach php tests/profiles_test.php --golden, falls gewollt)
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP:-$(command -v php || echo /opt/homebrew/bin/php)}"

while IFS= read -r -d '' f; do
    "$PHP" -l "$f" >/dev/null
done < <(find . -name '*.php' -not -path './.claude/*' -print0)
while IFS= read -r -d '' f; do
    "$PHP" -r 'json_decode(file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);' "$f"
done < <(find . -name '*.json' -not -path './.claude/*' -not -path './.API References/*' -not -path './.planning/*' -print0)
echo "Syntax und JSON in Ordnung."

status=0
for t in bridge device configurator transport shape profiles review_bridge review_device; do
    echo
    echo "### ${t}_test.php"
    if ! out=$("$PHP" "tests/${t}_test.php" "$@" 2>&1); then
        echo "$out" | grep -E '^(FAIL|OFFEN|BEHOBEN)' || echo "$out" | tail -5
        status=1
        continue
    fi
    echo "$out" | grep -E '^(OFFEN|BEHOBEN) ' || true
    echo "$out" | tail -1
done
exit $status
