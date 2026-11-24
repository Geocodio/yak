# Leftovers rules. Sourced by check-rules.sh.

# Only the app process writes the instance list; an extension's load fills tints in memory only.
tint_hits=$(grep -rn --include='*.swift' 'persistMissingTints' YakWidgets Shared 2>/dev/null || true)
if [ -n "$tint_hits" ]; then
    echo "$tint_hits"
    fail 'persistMissingTints called from an extension target'
fi

# The only background mode is fetch.
modes=$(perl -0777 -ne 'print "$1\n" if /UIBackgroundModes:\s*\n((?:\s+-\s+\S+\n)+)/' project.yml | sed 's/^[[:space:]]*-[[:space:]]*//' | sort | xargs)
[ "$modes" = "fetch" ] || fail "UIBackgroundModes in project.yml is not exactly fetch: $modes"
