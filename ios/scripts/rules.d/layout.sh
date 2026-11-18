# Layout rules. Sourced by check-rules.sh.

# Only "Open PR" is rust-colored on a thread screen; the layout and window code has none.
no_rust_folders+=(Yak/Layout Yak/Windows)

# PaneHost builds each pane view in one ForEach keyed by PaneID, so a pane that moves between slots is never rebuilt.
host_builds=$(grep -cF 'descriptor.content(' Yak/Thread/PaneHost.swift || true)
host_loops=$(grep -cF 'ForEach(slots.alive, id: \.self)' Yak/Thread/PaneHost.swift || true)
if [ "$host_builds" != "1" ] || [ "$host_loops" != "1" ]; then
    fail 'PaneHost builds a pane view anywhere but its one ForEach over slots.alive'
fi

# The iOS 27.1 layout APIs live in the files that guard them.
api_hits=$(grep -rnE --include='*.swift' 'onHingeChange|reservedRegions|ArrangementView|toolbarVerticalEdge' Yak 2>/dev/null \
    | grep -v '^Yak/Layout/HingeReader.swift:' | grep -v '^Yak/Layout/FoldSlots.swift:' \
    | grep -v '^Yak/Thread/PaneControl.swift:' | grep -vE '^[^:]+:[0-9]+:\s*///' || true)
if [ -n "$api_hits" ]; then
    echo "$api_hits"
    fail 'an iOS 27.1 hinge or strip API outside Yak/Layout/HingeReader.swift, Yak/Layout/FoldSlots.swift and Yak/Thread/PaneControl.swift'
fi

# The Debug test posture is read only inside #if DEBUG in the hinge reader.
posture_hits=$(grep -rnF --include='*.swift' 'yakTestPosture' Yak 2>/dev/null | grep -v '^Yak/Layout/HingeReader.swift:' | grep -vE '^[^:]+:[0-9]+:\s*///' || true)
if [ -n "$posture_hits" ]; then
    echo "$posture_hits"
    fail 'yakTestPosture outside Yak/Layout/HingeReader.swift'
fi
