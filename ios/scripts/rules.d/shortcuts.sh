# Shortcut rules. Sourced by check-rules.sh.

# Every keyboard shortcut is declared in AppShortcut and written through yakShortcut(_:), so the only call of
# .keyboardShortcut( is in Yak/Support/Shortcuts.swift. A dialog's system roles stay as they are.
shortcut_hits=$(grep -rnF --include='*.swift' '.keyboardShortcut(' Yak Shared 2>/dev/null \
    | grep -v '^Yak/Support/Shortcuts.swift:' \
    | grep -vF '.keyboardShortcut(.defaultAction)' \
    | grep -vF '.keyboardShortcut(.cancelAction)' || true)
if [ -n "$shortcut_hits" ]; then
    echo "$shortcut_hits"
    fail '.keyboardShortcut( outside Yak/Support/Shortcuts.swift'
fi

# Panes ask whether they are on screen through SceneModel.isShown(_:) or the isPaneShown environment value, so the
# focused pane is compared only in the shell and in the pane control.
focus_hits=$(grep -rnE --include='*.swift' '(==|!=|switch|case)[^\n]*focusedPane|focusedPane[[:space:]]*(==|!=)' Yak Shared 2>/dev/null \
    | grep -v '^Yak/Shell/' | grep -v '^Yak/Thread/PaneControl.swift:' || true)
if [ -n "$focus_hits" ]; then
    echo "$focus_hits"
    fail 'focusedPane compared outside Yak/Shell and Yak/Thread/PaneControl.swift'
fi
