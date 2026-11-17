# Share extension rules. Sourced by check-rules.sh.

targets+=(YakShare)
rust_allowed_files+=(Shared/Share/ShareScreen.swift)

grep -q 'YakShare/target.yml' project.yml || fail 'project.yml does not include YakShare/target.yml'
grep -q 'APPLICATION_EXTENSION_API_ONLY: YES' YakShare/target.yml || fail 'YakShare/target.yml does not set APPLICATION_EXTENSION_API_ONLY to YES'

# The extension reads the app's Keychain items: the first keychain group of both targets is the same one.
first_keychain_group() {
    perl -0777 -ne 'if (/<key>keychain-access-groups<\/key>\s*<array>\s*<string>(.*?)<\/string>/s) { print "$1\n" }' "$1"
}
app_group_first=$(first_keychain_group Yak/Yak.entitlements)
share_group_first=$(first_keychain_group YakShare/YakShare.entitlements)
[ -n "$app_group_first" ] && [ "$app_group_first" = "$share_group_first" ] \
    || fail 'the first keychain-access-groups entry differs between Yak.entitlements and YakShare.entitlements'
app_group_ids=$(perl -0777 -ne 'if (/<key>com.apple.security.application-groups<\/key>\s*<array>(.*?)<\/array>/s) { print "$_\n" for sort ($1 =~ m{<string>(.*?)</string>}g) }' Yak/Yak.entitlements)
share_group_ids=$(perl -0777 -ne 'if (/<key>com.apple.security.application-groups<\/key>\s*<array>(.*?)<\/array>/s) { print "$_\n" for sort ($1 =~ m{<string>(.*?)</string>}g) }' YakShare/YakShare.entitlements)
[ "$app_group_ids" = "$share_group_ids" ] || fail 'the app groups differ between Yak.entitlements and YakShare.entitlements'

grep -q '1C8F.1' Yak/Support/PrivacyInfo.xcprivacy || fail 'the app privacy manifest lacks 1C8F.1'
grep -q '1C8F.1' YakShare/PrivacyInfo.xcprivacy || fail 'the share privacy manifest lacks 1C8F.1'

# Extensions may not use UIApplication.shared.
ui_application_hits=$(grep -rnF 'UIApplication.shared' YakShare Shared/Share Yak/Composer/AttachmentPreparer.swift 2>/dev/null || true)
if [ -n "$ui_application_hits" ]; then
    echo "$ui_application_hits"
    fail 'UIApplication.shared in code the share extension compiles'
fi

# No Keychain group is named in code: the default group is the one both targets list first.
group_hits=$(grep -rnE 'kSecAttrAccessGroup|KeychainTokenStore\(accessGroup: *[^n]' --include='*.swift' Yak YakShare Shared 2>/dev/null | grep -v 'accessGroup: nil' || true)
if [ -n "$group_hits" ]; then
    echo "$group_hits"
    fail 'a Keychain access group is named in code'
fi

# A link, token or address never goes into a log in public.
log_hits=$(grep -rnE '\\\((url|link|token)[^)]*, *privacy: *\.public' --include='*.swift' Shared Yak/Uploads 2>/dev/null || true)
if [ -n "$log_hits" ]; then
    echo "$log_hits"
    fail 'a url, link or token is logged as public'
fi
