# Leftovers rules. Sourced by check-rules.sh.

# Only the app process writes the instance list; an extension's load fills tints in memory only.
tint_hits=$(grep -rn --include='*.swift' 'persistMissingTints' YakWidgets Shared 2>/dev/null || true)
if [ -n "$tint_hits" ]; then
    echo "$tint_hits"
    fail 'persistMissingTints called from an extension target'
fi

# Background modes are fetch and audio only.
modes=$(perl -0777 -ne 'print "$1\n" if /UIBackgroundModes:\s*\n((?:\s+-\s+\S+\n)+)/' project.yml | sed 's/^[[:space:]]*-[[:space:]]*//' | sort | xargs)
[ "$modes" = "audio fetch" ] || fail "UIBackgroundModes in project.yml is not exactly fetch and audio: $modes"

# The audio session is the video code's alone.
audio_hits=$(grep -rn --include='*.swift' 'AVAudioSession' Yak Shared 2>/dev/null | grep -v '^Yak/Video/' || true)
if [ -n "$audio_hits" ]; then
    echo "$audio_hits"
    fail 'AVAudioSession outside Yak/Video'
fi

# Walkthrough videos may leave the window, and say nothing about the work on the lock screen.
for pattern in 'allowsPictureInPicturePlayback = false' 'updatesNowPlayingInfoCenter = true'; do
    hits=$(grep -rnF --include='*.swift' "$pattern" Yak Shared 2>/dev/null || true)
    if [ -n "$hits" ]; then
        echo "$hits"
        fail "$pattern"
    fi
done
