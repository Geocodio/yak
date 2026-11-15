# Release rules. Sourced by check-rules.sh; defines functions only and registers them in
# deferred_checks, which check-rules.sh runs after every rules.d file has added its targets.
# Bash 3.2 compatible (macOS).

host_allowed_files=()

release_swift_files() {
    find "$@" -name '*.swift' -not -path '*/.build/*' 2>/dev/null | sort
}

# Every app extension target: type app-extension in project.yml or any */target.yml, plus the
# extension_targets override list. Prints one name per line, sorted, without duplicates.
release_extension_targets() {
    {
        ruby scripts/release-project.rb extension-targets
        [ "${#extension_targets[@]}" -gt 0 ] && printf '%s\n' "${extension_targets[@]}"
    } | sort -u
}

# release_scan <release-scan.pl arguments>: the file list arrives on stdin, one path per line, and is
# passed NUL-separated so a path with a space stays one argument.
release_scan() {
    tr '\n' '\0' | xargs -0 perl scripts/release-scan.pl "$@"
}

release_report() {
    local label="$1" hits="$2"
    if [ -n "$hits" ]; then
        echo "$hits"
        fail "release: $label"
    fi
}

release_check_logging() {
    release_report 'logging' "$(release_swift_files "${targets[@]}" | release_scan logging)"
}

release_check_traps() {
    release_report 'trap message' "$(release_swift_files "${targets[@]}" | release_scan traps)"
}

release_check_hosts() {
    local file allowed skip checked=""
    while IFS= read -r file; do
        skip=0
        for allowed in ${host_allowed_files[@]+"${host_allowed_files[@]}"}; do
            [ "$file" = "$allowed" ] && skip=1
        done
        [ "$skip" = 1 ] || checked="$checked$file"$'\n'
    done < <(release_swift_files "${targets[@]}" | grep -vE '^(YakUITests|YakTests|Packages/[^/]+/Tests|Packages/YakStub)/|^Yak/Support/TestMode\.swift$')
    release_report 'hosts (add the file to host_allowed_files with a reason if the host is intended)' \
        "$(echo "$checked" | sed '/^$/d' | release_scan hosts)"
}

# Catalogs for one target folder: its own xcstrings files and Shared/.
release_catalogs() {
    find "$1" Shared -name '*.xcstrings' -not -path '*/.build/*' 2>/dev/null | sort | paste -sd, -
}

release_check_catalog() {
    local folder catalogs files hits=""
    for folder in Yak $(release_extension_targets); do
        [ -d "$folder" ] || continue
        catalogs=$(release_catalogs "$folder")
        files=$(release_swift_files "$folder")
        [ -n "$files" ] || continue
        hits="$hits$(echo "$files" | release_scan catalog "$catalogs")"$'\n'
    done
    # ponytail: a key built in a variable or a LocalizedStringKey(...) is not checked.
    release_report 'catalog' "$(echo "$hits" | sed '/^$/d')"
}

# Is a non-empty usage string for $1 set in project.yml or the target's target.yml?
release_has_usage_string() {
    local key="$1" folder="$2" file
    for file in project.yml "$folder/target.yml"; do
        [ -f "$file" ] || continue
        grep -Eq "^[[:space:]]*(INFOPLIST_KEY_)?$key:[[:space:]]*([^[:space:]#\"'][^#]*|\"[^\"]+\"|'[^']+')[[:space:]]*(#.*)?$" "$file" && return 0
    done
    return 1
}

release_check_usage_strings() {
    local folder found api key hits=""
    for folder in Yak $(release_extension_targets); do
        [ -d "$folder" ] || continue
        found=$(release_swift_files "$folder" | release_scan tokens \
            'AVCaptureDevice|DataScannerViewController|VNDocumentCameraViewController|LAContext|PHPhotoLibrary|PHAsset|CLLocationManager|AVAudioRecorder|requestRecordPermission') \
            || hits="$hits$folder: a Swift file cannot be read, so its usage strings were not checked"$'\n'
        for api in $found; do
            case "$api" in
                AVCaptureDevice | DataScannerViewController | VNDocumentCameraViewController) key=NSCameraUsageDescription ;;
                LAContext) key=NSFaceIDUsageDescription ;;
                PHPhotoLibrary | PHAsset) key=NSPhotoLibraryUsageDescription ;;
                CLLocationManager) key=NSLocationWhenInUseUsageDescription ;;
                *) key=NSMicrophoneUsageDescription ;;
            esac
            release_has_usage_string "$key" "$folder" || hits="$hits$folder: uses $api but $key is missing or empty"$'\n'
        done
    done
    release_report 'usage strings' "$(echo "$hits" | sed '/^$/d')"
}

release_check_versions() {
    local hits="" plist
    perl -0777 -e 'open(F, "<", "VERSION") or exit 1; local $/; my $v = <F>; exit($v =~ /\A\d+\.\d+\.\d+\n?\z/ ? 0 : 1)' \
        || hits="VERSION: must hold a version like 1.2.0"$'\n'
    hits="$hits$(ruby scripts/release-project.rb versions)"$'\n'
    while IFS= read -r plist; do
        hits="$hits$(perl -0777 -ne '
            while (/<key>(CFBundleShortVersionString|CFBundleVersion)<\/key>\s*<string>([^<]*)<\/string>/g) {
                my $want = $1 eq "CFBundleVersion" ? q{$(CURRENT_PROJECT_VERSION)} : q{$(MARKETING_VERSION)};
                print "$ARGV: $1 is the literal \"$2\"; use $want\n" if $2 ne $want;
            }' "$plist")"$'\n'
    done < <(find . -name Info.plist -not -path './build/*' -not -path '*/.build/*' -not -path './vendor/*' -not -path '*.xcodeproj/*' | sed 's|^\./||' | sort)
    release_report 'version' "$(echo "$hits" | sed '/^$/d')"
}

release_check_sentry() {
    local hits
    hits=$(release_swift_files "${targets[@]}" | grep -v '^Yak/CrashReporting/' | tr '\n' '\0' | xargs -0 grep -nE 'import Sentry|SentrySDK' 2>/dev/null || true)
    hits="$hits"$'\n'$(release_swift_files "${targets[@]}" | tr '\n' '\0' | xargs -0 grep -n 'SentrySDK\.capture' 2>/dev/null || true)
    release_report 'sentry (import and SentrySDK stay in Yak/CrashReporting, and never capture by hand)' "$(echo "$hits" | sed '/^$/d' | sort -u)"
}

release_check_workflow() {
    local workflow="../.github/workflows/ios-release.yml"
    [ -f "$workflow" ] || return 0
    release_report 'workflow' "$(ruby scripts/release-project.rb workflow "$workflow")"
}

# Strings under a key of an entitlements file, sorted, one per line.
release_entitlement_strings() {
    perl -0777 -ne '
        while (/<key>\Q$ENV{KEY}\E<\/key>\s*<array>(.*?)<\/array>/sg) { my $body = $1; print "$_\n" for sort ($body =~ m{<string>(.*?)</string>}g); }
    ' "$1" 2>/dev/null
}

release_check_groups() {
    local target hits="" key
    for target in $(release_extension_targets); do
        if [ ! -f "$target/$target.entitlements" ]; then
            hits="$hits$target: $target/$target.entitlements is missing"$'\n'
            continue
        fi
        for key in com.apple.security.application-groups keychain-access-groups; do
            if [ "$(KEY=$key release_entitlement_strings "$target/$target.entitlements")" != "$(KEY=$key release_entitlement_strings Yak/Yak.entitlements)" ]; then
                hits="$hits$target/$target.entitlements: $key differs from Yak/Yak.entitlements"$'\n'
            fi
        done
    done
    release_report 'groups' "$(echo "$hits" | sed '/^$/d')"
}

release_check_minimum_versions() {
    release_report 'minimum version' "$(ruby scripts/release-project.rb minimum)"
}

release_check_privacy_manifests() {
    local folder manifest source_dirs found api category hits=""
    for folder in Yak $(release_extension_targets); do
        if [ "$folder" = Yak ]; then manifest=Yak/Support/PrivacyInfo.xcprivacy; else manifest="$folder/PrivacyInfo.xcprivacy"; fi
        if [ ! -f "$manifest" ]; then
            hits="$hits$folder: $manifest is missing"$'\n'
            continue
        fi
        perl -0777 -e 'local $/; open(F, "<", $ARGV[0]); my $t = <F>; exit($t =~ /<key>NSPrivacyTracking<\/key>\s*<false\/>/ ? 0 : 1)' "$manifest" \
            || hits="$hits$manifest: NSPrivacyTracking must be false"$'\n'
        source_dirs="$folder Shared Packages/YakKit/Sources"
        # shellcheck disable=SC2086
        found=$(release_swift_files $source_dirs | release_scan tokens \
            'UserDefaults|creationDate|modificationDate|contentModificationDateKey|creationDateKey|attributesOfItem|systemUptime|mach_absolute_time|volumeAvailableCapacity|systemFreeSize|activeInputModes') \
            || hits="$hits$manifest: a Swift file cannot be read, so its privacy categories were not checked"$'\n'
        for api in $found; do
            case "$api" in
                UserDefaults) category=NSPrivacyAccessedAPICategoryUserDefaults ;;
                creationDate | modificationDate | contentModificationDateKey | creationDateKey | attributesOfItem) category=NSPrivacyAccessedAPICategoryFileTimestamp ;;
                systemUptime | mach_absolute_time) category=NSPrivacyAccessedAPICategorySystemBootTime ;;
                volumeAvailableCapacity | systemFreeSize) category=NSPrivacyAccessedAPICategoryDiskSpace ;;
                *) category=NSPrivacyAccessedAPICategoryActiveKeyboards ;;
            esac
            grep -q "<string>$category</string>" "$manifest" || hits="$hits$manifest: uses $api but does not declare $category"$'\n'
        done
    done
    release_report 'privacy manifests' "$(echo "$hits" | sed '/^$/d')"
}

release_run_checks() {
    echo "release: extension targets: $(release_extension_targets | paste -sd' ' -)"
}

deferred_checks+=(
    release_run_checks
    release_check_logging
    release_check_traps
    release_check_hosts
    release_check_catalog
    release_check_usage_strings
    release_check_versions
    release_check_sentry
    release_check_workflow
    release_check_groups
    release_check_minimum_versions
    release_check_privacy_manifests
)
