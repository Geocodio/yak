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
[ -f .github/dependabot.yml ] && cp .github/dependabot.yml "$base/pristine/.github/"

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
for planted in 'logFailure("a \(error as NSError)")' 'logFailure("\(String(reflecting: error))")' \
    'logFailure("\(message.description)")' 'logFailure("\(thing.debugDescription)")' 'log.error("\(message.text)")'; do
    fresh
    printf 'func planted() { %s }\n' "$planted" | plant Yak/Planted.swift
    expect_fail "logging: $planted" "release: logging"
done

# focused pane
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { switch scene.state.focusedPane { default: break } }
EOF
expect_fail "focus: a switch on the raw focused pane" "focusedPane read outside" "Yak/Planted.swift:1"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { PaneMenuItems(slots: slots, focused: scene.state.focusedPane) }
EOF
expect_fail "focus: the raw focused pane as an argument" "focusedPane read outside" "Yak/Planted.swift:1"
fresh
plant Yak/Planted.swift <<'EOF'
func planted() { scene.shownPane(among: panes); scene.isShown(.chat) }
EOF
expect_pass "focus: the accessors pass"

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
fresh
plant Yak/Planted.swift <<'EOF'
let view = Text("caf\u{E9}")
EOF
plant Yak/Extra.xcstrings <<'EOF'
{"strings": {"caf\u00e9": {}}, "version": "1.0"}
EOF
expect_pass "catalog: a \\u{...} escape is decoded before the lookup"

fresh
printf '{"strings": ' > "$work/ios/Yak/Support/Localizable.xcstrings"
expect_fail "catalog: a catalog that is not valid JSON" "release: catalog"

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
permissions:
  contents: read
defaults:
  run:
    shell: bash
jobs:
  release:
    if: \${{ vars.YAK_IOS_RELEASE == 'on' && github.ref == 'refs/heads/main' }}
    environment: testflight
    runs-on: xcode-27
    steps:
      - uses: actions/checkout@v4
      - run: scripts/verify-release-tag.sh
      - env:
          ASC_KEY_ID: \${{ secrets.ASC_KEY_ID }}
        run: echo
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
fresh
valid_workflow
sed -i.bak '/if: .*YAK_IOS_RELEASE/d' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: no if on the release job" "release: workflow" "skips quietly"

fresh
valid_workflow
sed -i.bak "s# && github.ref == 'refs/heads/main'##" "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: a job that any branch can start" "release: workflow" "refs/heads/main"
fresh
valid_workflow
sed -i.bak '/^permissions:/,/contents: read/d' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: no read-only default permissions" "release: workflow" "contents: read"
fresh
valid_workflow
sed -i.bak '/run: scripts\/verify-release-tag.sh/d' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: a secret with no tag check before it" "release: workflow" "verify-release-tag.sh"
fresh
valid_workflow
perl -0pi -e 's/(    runs-on: xcode-27\n)/$1    env:\n      KEY: \${{ secrets.ASC_KEY_ID }}\n/' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: a secret in the job env" "release: workflow" "env must not hold a secret"
fresh
valid_workflow
sed -i.bak 's#actions/checkout@v4#someone/action@v1#' "$work/.github/workflows/ios-release.yml"
expect_fail "workflow: a third-party action not pinned to a commit" "release: workflow" "not pinned to a commit"
fresh
valid_workflow
sed -i.bak 's#actions/checkout@v4#someone/action@0123456789abcdef0123456789abcdef01234567#' "$work/.github/workflows/ios-release.yml"
expect_pass "workflow: a third-party action pinned to a commit passes"

# piped run blocks need pipefail
pipe_workflow() {
    mkdir -p "$work/.github/workflows"
    cat > "$work/.github/workflows/ios-extra.yml" <<WORKFLOW
name: extra
on: workflow_dispatch
jobs:
  piped:
    runs-on: xcode-27
    steps:
      - name: Piped
        run: |
          swift test 2>&1 | tee log
WORKFLOW
}
fresh
pipe_workflow
expect_fail "pipefail: a pipe with no shell" "release: pipefail" "pipes without pipefail"
fresh
pipe_workflow
perl -0pi -e 's/    runs-on: xcode-27\n/    defaults:\n      run:\n        shell: bash\n    runs-on: xcode-27\n/' "$work/.github/workflows/ios-extra.yml"
expect_pass "pipefail: shell bash on the job passes"
fresh
pipe_workflow
perl -0pi -e 's/          swift test/          set -o pipefail\n          swift test/' "$work/.github/workflows/ios-extra.yml"
expect_pass "pipefail: set -o pipefail in the block passes"
fresh
pipe_workflow
sed -i.bak 's/swift test 2>&1 | tee log/swift test || true/' "$work/.github/workflows/ios-extra.yml"
expect_pass "pipefail: || is not a pipe"

# the hosts rule names the line of the literal
fresh
plant Yak/Planted.swift <<'PLANTED'
import Foundation

let first = 1
let link = "https://real.example/path"
PLANTED
expect_fail "hosts: the report names the literal's line" "release: hosts" "Planted.swift:4:"

# extension targets: discovery, groups, override, minimum version, privacy
planted_share() {
    plant YakPlanted/target.yml <<'EOF'
targets:
  YakPlanted:
    type: app-extension
    platform: iOS
EOF
    cp "$work/ios/Yak/Yak.entitlements" "$work/ios/YakPlanted/YakPlanted.entitlements"
}
fresh
planted_share
expect_fail "discovery: an app-extension target that no file registers" "release: privacy manifests" "YakPlanted: YakPlanted/PrivacyInfo.xcprivacy is missing"
if ! grep -qF "release: extension targets: YakPlanted" <<<"$output"; then
    echo "FAIL  discovery: the output lacks 'release: extension targets: YakPlanted'"
    failures=$((failures + 1))
fi
fresh
planted_share
cp "$work/ios/Yak/Support/PrivacyInfo.xcprivacy" "$work/ios/YakPlanted/PrivacyInfo.xcprivacy"
expect_pass "discovery: a complete extension target passes"
fresh
planted_share
cp "$work/ios/Yak/Support/PrivacyInfo.xcprivacy" "$work/ios/YakPlanted/PrivacyInfo.xcprivacy"
sed -i.bak 's/\$(YAK_APP_GROUP)/group.com.example.other/' "$work/ios/YakPlanted/YakPlanted.entitlements"
expect_fail "groups: another app group" "release: groups" "YakPlanted"
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
cp "$work/ios/Yak/Support/PrivacyInfo.xcprivacy" "$work/ios/YakPlanted/PrivacyInfo.xcprivacy"
cat >> "$work/ios/YakPlanted/target.yml" <<'EOF'
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
if ! grep -qF "release: hosts" <<<"$output"; then
    echo "FAIL  a file name with a space: the hosts rule did not name it"
    failures=$((failures + 1))
fi
fresh
plant Yak/Support/Hidden.swift <<'EOF'
let manager = CLLocationManager()
EOF
chmod 000 "$work/ios/Yak/Support/Hidden.swift"
expect_fail "an unreadable Swift file is a finding, not a silent pass" "release: usage strings" "cannot be read"
chmod 600 "$work/ios/Yak/Support/Hidden.swift"
fresh
plant Yak/Raw.swift <<'EOF'
let raw = #"say "hi" at https://help.example.org"#
let block = """
https://help.example.org
"""
EOF
expect_fail "hosts: a raw string and a triple-quoted string" "release: hosts" "Yak/Raw.swift"
fresh
plant YakPlanted/target.yml <<'EOF'
targets: [unclosed
EOF
expect_fail "a target.yml that is not YAML is named, not read as target names" "YakPlanted/target.yml: cannot be read as YAML"
if grep -q "extension targets: .*cannot" <<<"$output"; then
    echo "FAIL  the YAML finding leaked into the target names"
    failures=$((failures + 1))
fi

# nightly workflow, Dependabot and the pins package
fresh
rm "$work/.github/dependabot.yml"
expect_fail "dependabot: the file is missing" "release: dependabot" "is missing"
fresh
sed -i.bak 's/package-ecosystem: bundler/package-ecosystem: npm/' "$work/.github/dependabot.yml"
expect_fail "dependabot: bundler is not covered" "release: dependabot" "bundler"
fresh
sed -i.bak 's#/ios/Packages/Pins#/ios/Packages/YakKit#' "$work/.github/dependabot.yml"
expect_fail "dependabot: swift points away from Pins" "release: dependabot" "/ios/Packages/Pins"
fresh
sed -i.bak "s/^  schedule:/  pull_request:\\
  schedule:/" "$work/.github/workflows/ios-nightly.yml"
expect_fail "nightly: a pull_request trigger" "release: nightly" "pull_request"
fresh
printf '\n# uses secrets.KEY\n' >> "$work/.github/workflows/ios-nightly.yml"
expect_fail "nightly: a secrets reference" "release: nightly" "secrets"
fresh
sed -i.bak 's/timeout-minutes: 40/timeout-minutes: 90/' "$work/.github/workflows/ios-nightly.yml"
expect_fail "nightly: a job without the 40 minute bound" "release: nightly" "timeout-minutes"
fresh
plant project-planted.yml <<'EOF'
packages:
  Widget:
    url: https://github.com/example/widget.git
    exactVersion: 1.0.0
EOF
sed -i.bak 's#dependencies: \[#dependencies: [\
        .package(url: "https://github.com/example/widget.git", exact: "1.0.0"),#' "$work/ios/Packages/Pins/Package.swift"
rm -f "$work/ios/Packages/Pins/Package.resolved"
expect_fail "pins: Package.resolved is missing" "release: pins" "Package.resolved"
fresh
sed -i.bak 's#dependencies: \[#dependencies: [\
        .package(url: "https://github.com/example/unused.git", exact: "1.0.0"),#' "$work/ios/Packages/Pins/Package.swift"
expect_fail "pins: a package no project file uses" "release: pins" "example/unused"
fresh
plant project-planted.yml <<'EOF'
packages:
  Widget:
    url: https://github.com/example/widget.git
    exactVersion: 2.0.0
EOF
expect_fail "pins: a project package that Pins does not pin" "release: pins" "example/widget"
fresh
plant project-planted.yml <<'EOF'
packages:
  Widget:
    url: https://github.com/example/widget.git
    exactVersion: 1.0.0
EOF
sed -i.bak 's#dependencies: \[#dependencies: [\
        .package(url: "https://github.com/example/widget.git", exact: "1.0.1"),#' "$work/ios/Packages/Pins/Package.swift"
expect_fail "pins: a version one patch above the project pin" "release: pins" "change both in the same pull request"
fresh
sed -i.bak 's#dependencies: \[#dependencies: [\
        .package(url: "https://github.com/example/widget.git", from: "1.0.0"),#' "$work/ios/Packages/Pins/Package.swift"
expect_fail "pins: a range instead of exact" "release: pins" "exact:"
fresh
plant project-planted.yml <<'EOF'
packages:
  Widget:
    url: https://github.com/example/widget.git
    exactVersion: 1.0.0
EOF
sed -i.bak 's#dependencies: \[#dependencies: [\
        .package(url: "https://github.com/example/widget.git", exact: "1.0.0"),#' "$work/ios/Packages/Pins/Package.swift"
echo '{"pins":[],"version":3}' > "$work/ios/Packages/Pins/Package.resolved"
expect_pass "pins: matching versions pass"

# the release tag check: an annotated tag on the trusted branch passes, anything else is refused
tag_check() {
    # tag_check <name> <expected exit status> <text the output must hold> <args...>
    local name="$1" expected="$2" text="$3"
    shift 3
    local tag_output status
    tag_output=$(cd "$tagrepo" && TRUSTED_REF=main ios/scripts/verify-release-tag.sh "$@" 2>&1)
    status=$?
    if [ "$status" -ne "$expected" ] || ! grep -qF -- "$text" <<<"$tag_output"; then
        echo "FAIL  $name: status $status, output '$tag_output'"
        failures=$((failures + 1))
    else
        echo "ok    $name"
    fi
}
tagrepo="$base/tagrepo"
mkdir -p "$tagrepo/ios/scripts"
cp "$repository/ios/scripts/verify-release-tag.sh" "$tagrepo/ios/scripts/"
(
    cd "$tagrepo" || exit 1
    git init -q -b main . && git config user.email t@example.invalid && git config user.name t
    git commit -q --allow-empty -m trusted && git tag -a ios/v1.0.0-1 -m release
    git tag ios/v1.0.0-2
    git checkout -q -b other && git commit -q --allow-empty -m unrelated
    git tag -a ios/v1.0.0-3 -m release && git tag -a ios/vx -m release
    git checkout -q main
)
tag_check "tag check: an annotated tag on the trusted branch" 0 "" ios/v1.0.0-1
tag_check "tag check: a lightweight tag" 1 "not an annotated tag" ios/v1.0.0-2
tag_check "tag check: an annotated tag on another branch" 1 "not on main" ios/v1.0.0-3
tag_check "tag check: a tag with the wrong name" 1 "is not named" ios/vx
tag_check "tag check: a tag that does not exist" 1 "not an annotated tag" ios/v9.9.9-9
tag_check "tag check: no argument checks every tag" 1 "ios/v1.0.0-3" 
if (cd "$tagrepo" && TRUSTED_REF=origin/missing ios/scripts/verify-release-tag.sh ios/v1.0.0-1 >/dev/null 2>&1); then
    echo "FAIL  tag check: a missing trusted ref passed"
    failures=$((failures + 1))
else
    echo "ok    tag check: a missing trusted ref is refused"
fi

if [ "$failures" -ne 0 ]; then
    echo "$failures case(s) failed after $(($(date +%s) - started)) seconds"
    exit 1
fi
echo "all cases behave as expected ($(($(date +%s) - started)) seconds)"
