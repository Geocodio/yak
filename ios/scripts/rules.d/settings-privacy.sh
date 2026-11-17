# Settings and privacy rules. Sourced by check-rules.sh.

# Each system API has one file that may name it.
only_in() {
    local pattern="$1" allowed="$2" hits
    hits=$(grep -rnF --include='*.swift' "$pattern" "${targets[@]}" 2>/dev/null | grep -vF "$allowed:" || true)
    if [ -n "$hits" ]; then
        echo "$hits"
        fail "$pattern outside $allowed"
    fi
}

only_in 'LAContext' Yak/System/AppLock/DeviceOwnerAuthenticator.swift
only_in 'evaluatePolicy' Yak/System/AppLock/DeviceOwnerAuthenticator.swift
only_in 'CSSearchableIndex' Yak/System/Spotlight/CoreSpotlightWriter.swift
only_in 'CSSearchableItemAttributeSet' Yak/System/Spotlight/CoreSpotlightWriter.swift
only_in 'OSLogStore' Yak/Debug/LogReader.swift
only_in 'AppTransaction' Yak/Debug/BuildKindReader.swift
only_in 'Tips.configure' Yak/System/Tips/GestureTips.swift

# Handoff sets the web address and nothing else.
handoff_hits=$(grep -rnE --include='*.swift' 'NSUserActivity|\.userActivity\(' "${targets[@]}" 2>/dev/null \
    | grep -v 'Yak/System/Handoff/ThreadHandoff.swift:' | grep -v 'Yak/YakApp.swift:' | grep -v 'Yak/System/Spotlight/' || true)
if [ -n "$handoff_hits" ]; then
    echo "$handoff_hits"
    fail 'NSUserActivity outside ThreadHandoff.swift, the Spotlight indexer and YakApp.swift'
fi
if [ -f Yak/System/Handoff/ThreadHandoff.swift ] \
    && grep -nE '\.title = [^n]|userInfo = \[[^]:]|keywords = \[[^]]' Yak/System/Handoff/ThreadHandoff.swift; then
    fail 'ThreadHandoff.swift sets a title, user info or keywords'
fi

# The "This device" copy never names the device model.
check 'UIDevice.current.model' 'UIDevice.current.model'

# Every package the app links (other than YakKit) has its license text and a line in the list, and every text file
# is named in the list.
for package in $(awk '/^packages:/{on=1;next} on && /^[^ ]/{on=0} on && /^  [A-Za-z0-9_.-]+:/{gsub(/[ :]/,"");print}' project.yml); do
    [ "$package" = YakKit ] && continue
    [ -f "Yak/Settings/Licenses/$package.txt" ] || fail "Yak/Settings/Licenses/$package.txt is missing"
    grep -qF "\"$package\"" Yak/Settings/LicenseList.swift || fail "LicenseList.swift does not name $package"
done
for text in Yak/Settings/Licenses/*.txt; do
    grep -qF "\"$(basename "$text" .txt)\"" Yak/Settings/LicenseList.swift || fail "$text is not named in LicenseList.swift"
done
