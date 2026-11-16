# Push rules. Sourced by check-rules.sh.

# The widget extension's sources are checked like the app's.
targets+=(YakWidgets)

grep -q 'YakWidgets/target.yml' project.yml || fail 'project.yml does not include YakWidgets/target.yml'
grep -q 'NSSupportsLiveActivities' project.yml || fail 'project.yml does not set NSSupportsLiveActivities'

# ActivityKit decodes the content state with a default JSONDecoder, which cannot read an ISO 8601 string as a Date.
if grep -qE ':[[:space:]]*Date\b' Packages/YakKit/Sources/YakKit/Push/ThreadActivityContent.swift; then
    fail 'ThreadActivityContent.swift holds a Date field; the content state keeps times as strings'
fi

# The widget extension and the app open the same groups. A mismatch is also reported by the release rules, which
# compare every extension against the app; this names the push reason.
for key in com.apple.security.application-groups keychain-access-groups; do
    app_groups=$(perl -0777 -ne 'while (/<key>\Q'"$key"'\E<\/key>\s*<array>(.*?)<\/array>/sg) { print "$_\n" for sort ($1 =~ m{<string>(.*?)</string>}g) }' Yak/Yak.entitlements)
    widget_groups=$(perl -0777 -ne 'while (/<key>\Q'"$key"'\E<\/key>\s*<array>(.*?)<\/array>/sg) { print "$_\n" for sort ($1 =~ m{<string>(.*?)</string>}g) }' YakWidgets/YakWidgets.entitlements 2>/dev/null)
    [ "$app_groups" = "$widget_groups" ] || fail "YakWidgets.entitlements differs from Yak.entitlements for $key"
done
