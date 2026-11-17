# Widget, intent and quick action rules. Sourced by check-rules.sh.

targets+=(YakWidgets)

# Every title passes the private rule in YakKit: views and intents read the content types, never a snapshot's lists.
surface_folders=(Yak/Widgets Yak/Intents Yak/QuickActions Shared/Widgets Shared/Intents YakWidgets)
for folder in "${surface_folders[@]}"; do
    [ -d "$folder" ] || continue
    hits=$(grep -rnE --include='*.swift' '[sS]napshot[A-Za-z]*\??\.(needsYou|running)\b' "$folder" || true)
    if [ -n "$hits" ]; then
        echo "$hits"
        fail 'a widget, intent or quick action reads a snapshot list directly; use the YakKit content types'
    fi
done

# The extension reads the instance list and the tokens; it never changes them.
for folder in YakWidgets Shared; do
    hits=$(grep -rnE --include='*.swift' 'setNeedsYouCount|InstanceStore\.add|\.add\(url:|setToken|removeToken' "$folder" 2>/dev/null \
        | grep -v 'Shared/Storage/SharedStorage.swift' || true)
    if [ -n "$hits" ]; then
        echo "$hits"
        fail 'the widget extension or Shared code writes the instance list or a token'
    fi
done

[ -f YakWidgets/PrivacyInfo.xcprivacy ] || fail 'YakWidgets/PrivacyInfo.xcprivacy is missing'
grep -q 'aps-environment' YakWidgets/YakWidgets.entitlements || fail 'YakWidgets.entitlements lacks aps-environment'
