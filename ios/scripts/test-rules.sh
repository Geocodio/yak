#!/usr/bin/env bash
# Plants one violation at a time in a copy of ios/ and expects scripts/check-rules.sh to name it.
# Also plants the lookalikes that must pass. Exits non-zero when any case behaves otherwise.
set -u
started=$(date +%s)
echo "test-rules: copying ios/ and planting one case at a time (about a minute)"
cd "$(dirname "$0")/../.."
repository=$(pwd)
base=$(mktemp -d)
trap 'rm -rf "$base"' EXIT
mkdir -p "$base/pristine"
rsync -a --exclude build --exclude .build --exclude vendor --exclude '*.xcodeproj' ios "$base/pristine/"
if [ -d .github/workflows ]; then
    mkdir -p "$base/pristine/.github"
    rsync -a .github/workflows "$base/pristine/.github/"
fi

failures=0
work=""
output=""

fresh() {
    work="$base/case"
    rm -rf "$work"
    cp -R "$base/pristine" "$work"
}

# plant <relative path in ios/>: writes stdin there.
plant() {
    mkdir -p "$(dirname "$work/ios/$1")"
    cat > "$work/ios/$1"
}

append() {
    cat >> "$work/ios/$1"
}

run_rules() {
    output=$(cd "$work/ios" && scripts/check-rules.sh 2>&1)
    return $?
}

# expect_fail <name> <label> [<text that must also be in the output>]
expect_fail() {
    if run_rules; then
        echo "FAIL  $1: the rules passed"
        failures=$((failures + 1))
    elif ! grep -qF -- "$2" <<<"$output" || { [ $# -ge 3 ] && ! grep -qF -- "$3" <<<"$output"; }; then
        echo "FAIL  $1: the output lacks '$2'${3:+ or '$3'}"
        echo "$output" | sed 's/^/        /'
        failures=$((failures + 1))
    else
        echo "ok    $1"
    fi
}

expect_pass() {
    if run_rules; then
        echo "ok    $1"
    else
        echo "FAIL  $1: the rules failed"
        echo "$output" | sed 's/^/        /'
        failures=$((failures + 1))
    fi
}

fresh
expect_pass "the tree as it is"
fresh
for other in "$work"/ios/scripts/rules.d/*.sh; do [ "$(basename "$other")" = release.sh ] || rm -f "$other"; done
expect_pass "rules.d holds only release.sh"
if [ -n "$(cd "$repository/ios" && bash -c '. scripts/rules.d/release.sh' 2>&1)" ]; then
    echo "FAIL  release.sh prints something when sourced alone"
    failures=$((failures + 1))
else
    echo "ok    release.sh is silent when sourced alone"
fi

# logging
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { logFailure("sent \(message.text)") }
EOF
expect_fail "logging: message text" "release: logging" "Yak/Planted.swift:1"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() {
    Logger(subsystem: "Yak", category: "X")
        .error(
            "failed \(authorizationHeader)"
        )
}
EOF
expect_fail "logging: call split over several lines" "release: logging" "Yak/Planted.swift:4"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { logFailure("failed: \(error)") }
EOF
expect_fail "logging: the error itself" "release: logging"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { log("sent \(threadTitle)") }
EOF
expect_fail "logging: a title" "release: logging"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() {
    logFailure("failed: \(type(of: error))")
    log("failed: \(Swift.type(of: error))")
    logFailure("skipped \(context.kind)")
    logFailure("lengths \(body.count)")
    logFailure("kind \(contentType) \(subtitle)")
    let rows = catalog("\(title)")
    let notes = dialog("\(title)")
    // logFailure("sent \(message.text)")
    let sample = "logFailure(\"\(message.text)\")"
}
EOF
expect_pass "logging: lookalikes pass"

# trap messages
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { fatalError("bad \(title)") }
EOF
expect_fail "trap: interpolated literal" "release: trap message"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { fatalError(message) }
EOF
expect_fail "trap: variable" "release: trap message"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { precondition(isReady, reason) }
EOF
expect_fail "trap: second argument of precondition" "release: trap message"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() {
    fatalError("unreachable")
    precondition(isReady, "not ready")
    fatalError()
    assert(isReady)
}
EOF
expect_pass "trap: plain literals and no message pass"

# a later rules file
fresh
plant scripts/rules.d/zz-test.sh <<'EOF'
targets+=(Planted)
EOF
plant Planted/Bad.swift <<'EOF'
func planted() { logFailure("sent \(message.text)") }
EOF
expect_fail "a target added by a later rules file is checked" "release: logging" "Planted/Bad.swift"

# hosts
fresh
plant Yak/Planted.swift <<'EOF'
let help = URL(string: "https://help.example.org/yak")
EOF
expect_fail "hosts: a literal host" "release: hosts" "Yak/Planted.swift"
fresh
plant Yak/Planted.swift <<'EOF'
let help = URL(string: "https://help.example.org/yak")
let bare = "https://"
let scheme = "yak://"
let built = "\(scheme)://\(host)"
EOF
plant scripts/rules.d/zz-test.sh <<'EOF'
host_allowed_files+=(Yak/Planted.swift)
EOF
expect_pass "hosts: an allowed file passes"
fresh
plant Yak/Planted.swift <<'EOF'
let bare = "https://"
let scheme = "yak://"
EOF
expect_pass "hosts: a bare scheme passes"

# catalog
fresh
plant Yak/Planted.swift <<'EOF'
let view = Text("missing.key")
EOF
expect_fail "catalog: a key that is not in the catalog" "release: catalog" "missing.key"
fresh
plant Yak/Planted.swift <<'EOF'
let view = Text("connect.title")
let other = Text("pairAgain.title \(name)")
let verbatim = Text(verbatim: "not a key")
let dynamic = Text(LocalizedStringKey(someKey))
EOF
expect_pass "catalog: keys, interpolations and verbatim pass"

# usage strings
fresh
plant Yak/Support/UsesLocation.swift <<'EOF'
let manager = CLLocationManager()
EOF
expect_fail "usage: location without a usage string" "release: usage strings" "NSLocationWhenInUseUsageDescription"

# version
fresh
printf '1.2\n' > "$work/ios/VERSION"
expect_fail "version: a two part number" "release: version"
fresh
perl -0pi -e 's/(        PRODUCT_NAME: Yak\n)/$1        MARKETING_VERSION: "9.9.9"\n/' "$work/ios/project.yml"
expect_fail "version: a version key under a target" "release: version" "MARKETING_VERSION"
fresh
perl -0pi -e 's/(<key>CFBundleVersion<\/key>\s*<string>)[^<]*/${1}7/' "$work/ios/Yak/Support/Info.plist"
expect_fail "version: a literal build number in Info.plist" "release: version" "CFBundleVersion"

# sentry
fresh
printf 'import Sentry\n' | append Yak/Home/SignedInView.swift
expect_fail "sentry: an import outside Yak/CrashReporting" "release: sentry"
fresh
plant Yak/CrashReporting/CrashReporting.swift <<'EOF'
import Sentry
func start() { SentrySDK.start { _ in } }
EOF
expect_pass "sentry: the import inside Yak/CrashReporting passes"

# the release workflow
valid_workflow() {
    mkdir -p "$work/.github/workflows"
    cat > "$work/.github/workflows/ios-release.yml" <<EOF
name: iOS release
on:
  push:
    branches: [main]
  workflow_dispatch:
concurrency:
  group: ios-release
  cancel-in-progress: false
jobs:
  release:
    if: \${{ vars.YAK_IOS_RELEASE == 'on' }}
    environment: testflight
    runs-on: xcode-27
    steps:
      - run: echo
EOF
}
fresh
valid_workflow
expect_pass "workflow: a valid release workflow passes"
fresh
valid_workflow
perl -0pi -e 's/  workflow_dispatch:/  pull_request:\n  workflow_dispatch:/' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: a pull_request trigger" "release: workflow" "pull_request"
fresh
valid_workflow
sed -i.bak '/environment: testflight/d' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: no environment" "release: workflow" "environment: testflight"
fresh
valid_workflow
sed -i.bak "s/vars.YAK_IOS_RELEASE == 'on'/vars.YAK_BUNDLE_ID != ''/" "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: another variable in the job's if" "release: workflow" "YAK_BUNDLE_ID"

# extension targets: discovery, groups, override, minimum version, privacy
planted_share() {
    plant YakShare/target.yml <<'EOF'
targets:
  YakShare:
    type: app-extension
    platform: iOS
EOF
    cp "$work/ios/Yak/Yak.entitlements" "$work/ios/YakShare/YakShare.entitlements"
}
fresh
planted_share
expect_fail "discovery: an app-extension target that no file registers" "release: privacy manifests" "YakShare: YakShare/PrivacyInfo.xcprivacy is missing"
fresh
planted_share
cp "$work/ios/Yak/Support/PrivacyInfo.xcprivacy" "$work/ios/YakShare/PrivacyInfo.xcprivacy"
expect_pass "discovery: a complete extension target passes"
fresh
planted_share
cp "$work/ios/Yak/Support/PrivacyInfo.xcprivacy" "$work/ios/YakShare/PrivacyInfo.xcprivacy"
sed -i.bak 's/\$(YAK_APP_GROUP)/group.com.example.other/' "$work/ios/YakShare/YakShare.entitlements"
expect_fail "groups: another app group" "release: groups" "YakShare"
fresh
plant YakOther/YakOther.entitlements <<'EOF'
<?xml version="1.0" encoding="UTF-8"?>
<plist version="1.0"><dict>
	<key>com.apple.security.application-groups</key>
	<array><string>group.com.example.other</string></array>
</dict></plist>
EOF
plant scripts/rules.d/zz-test.sh <<'EOF'
extension_targets+=(YakOther)
EOF
expect_fail "override: extension_targets names a target the scan cannot see" "release: groups" "YakOther"
fresh
planted_share
cp "$work/ios/Yak/Support/PrivacyInfo.xcprivacy" "$work/ios/YakShare/PrivacyInfo.xcprivacy"
cat >> "$work/ios/YakShare/target.yml" <<'EOF'
    deploymentTarget: "27.1"
EOF
expect_fail "minimum version: a target above the project's" "release: minimum version" "27.1"
fresh
perl -pi -e 's/NSPrivacyAccessedAPICategoryUserDefaults/NSPrivacyAccessedAPICategoryOther/' "$work/ios/Yak/Support/PrivacyInfo.xcprivacy"
expect_fail "privacy: a missing required-reason category" "release: privacy manifests" "NSPrivacyAccessedAPICategoryUserDefaults"

# hostile input: every rule must stay quiet or name the finding, never hang or crash
fresh
: > "$work/ios/Yak/Empty.swift"
expect_pass "hostile: an empty Swift file"
fresh
plant Yak/Unterminated.swift <<'EOF'
func broken() { let a = Text("open
let b = foo(1, (2
let c = "http
EOF
expect_pass "hostile: an unterminated string and parenthesis"
fresh
plant Yak/Control.swift <<'EOF'
let a = Text("tab\tnew\nline \u{1F600} \u{0} \0 \\")
let b = Text("bell \u{7}")
EOF
printf 'let c = Text("raw \001 \033 control")\n' >> "$work/ios/Yak/Control.swift"
expect_fail "hostile: control characters and unicode escapes in a catalog key" "release: catalog" "is not a key"
fresh
perl -e 'print "let x = foo(\"a\", (1 + 2), [3]) // log(\"x\")\nlog(\"ok \\(count)\")\n" for 1..60000' > "$work/ios/Yak/Huge.swift"
perl -e 'print "log(\"a\" + ((\n" for 1..300' > "$work/ios/Yak/Unclosed.swift"
hugeStarted=$(date +%s)
expect_pass "hostile: a file of several MB"
if [ $(($(date +%s) - hugeStarted)) -gt 60 ]; then
    echo "FAIL  hostile: the multi-MB file took more than 60 seconds"
    failures=$((failures + 1))
fi
fresh
plant "Yak/My Planted.swift" <<'EOF'
func planted() { logFailure("sent \(message.text)") }
let host = "https://help.example.org"
EOF
expect_fail "a file name with a space is still scanned" "release: logging" "My Planted.swift"
fresh
plant Yak/Raw.swift <<'EOF'
let raw = #"say "hi" at https://help.example.org"#
let block = """
https://help.example.org
"""
EOF
expect_fail "hosts: a raw string and a triple-quoted string" "release: hosts" "Yak/Raw.swift"
fresh
plant YakShare/target.yml <<'EOF'
targets: [unclosed
EOF
expect_fail "a target.yml that is not YAML is named, not read as target names" "YakShare/target.yml: cannot be read as YAML"
if grep -q "extension targets: .*cannot" <<<"$output"; then
    echo "FAIL  the YAML finding leaked into the target names"
    failures=$((failures + 1))
fi

if [ "$failures" -ne 0 ]; then
    echo "$failures case(s) failed after $(($(date +%s) - started)) seconds"
    exit 1
fi
echo "all cases behave as expected ($(($(date +%s) - started)) seconds)"
