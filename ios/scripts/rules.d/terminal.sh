# Terminal and Files rules. Sourced by check-rules.sh.

no_rust_folders+=(Yak/Terminal Yak/Files)

# The emulator package is imported in one file, so it can be swapped.
swiftterm_hits=$(grep -rnF --include='*.swift' 'import SwiftTerm' Yak Shared 2>/dev/null \
    | grep -v '^Yak/Terminal/SwiftTermSurface.swift:' || true)
if [ -n "$swiftterm_hits" ]; then
    echo "$swiftterm_hits"
    fail 'import SwiftTerm outside Yak/Terminal/SwiftTermSurface.swift'
fi

# Server file content is never written through the cache.
content_hits=$(grep -rnE --include='*.swift' '\b(FileContent|FileBody)\b' Packages/YakKit/Sources/YakKit/Cache 2>/dev/null \
    | grep -v 'Cache+FileDrafts.swift' || true)
if [ -n "$content_hits" ]; then
    echo "$content_hits"
    fail 'FileContent or FileBody under Packages/YakKit/Sources/YakKit/Cache'
fi

# The app never sends the bridge's ping frame on a timer.
ping_hits=$(grep -rnF --include='*.swift' '"type":"ping"' Packages/YakKit/Sources 2>/dev/null || true)
if [ -n "$ping_hits" ]; then
    echo "$ping_hits"
    fail 'a ping frame in YakKit'
fi
