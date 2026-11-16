# Yak for iPhone and iPad

Each company builds its own copy. Nothing in this folder holds a team ID, bundle identifier or key.

## Setup

1. Install Xcode (the version in `.xcode-version`), XcodeGen and bundler: `brew install xcodegen`, then `cd ios && bundle config set --local path vendor/bundle && bundle install`.
2. Copy `Config.example.xcconfig` to `Config.xcconfig` (gitignored) and fill in your team ID, bundle identifier, app group and display name.
3. `bundle exec fastlane generate` creates `Yak.xcodeproj` from `project.yml`.

## Everyday commands

- `bundle exec fastlane lint` checks formatting and the project rules.
- `bundle exec fastlane test` runs the `YakKit` tests and the app unit tests.
- `cd Packages/YakKit && swift test` runs only the package tests (also works on Linux).

`YakKit` holds all logic and imports no UIKit or SwiftUI. The app target only shows its models.

## UI tests and the stub server

`bundle exec fastlane ui_smoke device:"iPhone 18 Pro"` builds `Packages/YakStub`, starts `yak-stub` on a free port, runs `YakUITests` against it and stops it. The stub replays the contract fixtures in `fixtures/`; a test picks the scenario with `StubControl.play(_:)`, which posts the name to `/__stub/scenario`. Scenarios: `paired`, `revoked`, `server-too-new`, `server-too-old`, `not-yak`, `slow` (every answer after 10 seconds), `slower` (14 seconds, so the slow-connection hint shows before the answer arrives), `down` (connections closed), `broken` (500 everywhere), `code-used`, `code-expired`, `approval-pending` (`device/token` answers `authorization_pending` for ever), `approval-denied`, `approval-expired`, `approval-slow-down`. `POST /__stub/online` with `no` or `yes` takes the app offline and back in test mode (a new scenario resets it); `StubControl.setOnline(_:)` does this from a test.

Debug builds understand the launch arguments `-yakTestInstance <url>` (test mode: in-memory storage, plain `http://127.0.0.1` accepted), `-yakTestToken <token>` (start paired with that instance) and `-yakTestKeychainLocked YES` (token reads fail). Release builds contain none of this.

`bundle exec fastlane ui_smoke only:testName` runs one test of `PairingSmokeTests`. Add `device:"iPhone Duo"` for another simulator.

Features add files and no lines: `ui_smoke` runs every class in `YakUITests/*SmokeTests.swift` and the core classes listed in `CORE_SMOKE_CLASSES` in the Fastfile, `walkthrough` every class in `YakUITests/*WalkthroughTests.swift`, and `only:` takes `Class/test` or a bare test name that must be in exactly one class. A feature smoke test runs on the iPhone row only, and `core_only:true` (the iPad and the Duo rows) runs the core classes, so a feature that wants those rows adds its class there. A feature's project rules live in `scripts/rules.d/<feature>.sh`, which may add to `targets`, `no_rust_folders` and `rust_allowed_files`; its stub helpers go in `StubControl+<Feature>.swift`, which uses `StubControl.post(_:_:)` and `get(_:)`.

Push scenarios (played with `StubControl.play(_:)`): `push-unavailable` (the instance has no notification key), `push-other-build`, `push-other-environment`, `push-registration-failing` (every token registration answers 503), `push-test-rejected` (the test notification is refused) and `notifications-failing` (saving a switch fails). `GET /__stub/push` answers what the stub holds: the registered `tokens` per kind, the reported `activities` and the saved `preferences`; `StubControl.pushState()` reads it. Push launch arguments, all Debug only: `-yakTestNotifications notDetermined|authorized|denied`, `-yakTestNotificationRequest granted|denied|failed|slow`, `-yakTestPushToken fail`, `-yakTestPushBuild <bundle id>/<environment>`, `-yakTestLiveActivities off`, `-yakTestHoldExplanation YES`, `-yakTestPushTap <base64 payload>` (a tap that launched the app) and `-yakTestScreen live-activity-gallery` with the environment variable `YAK_TEST_GALLERY_STATE` (`working`, `working-no-step`, `needs-you`, `finished`, `failed`, `stopped`, `stale`, `unknown-state`, `private`, `long-title`). While the app runs, `yak://test-push?payload=<base64 payload>` taps a push. `StubControl.pushPayload(_:)` builds the payload from a `scenario-push-alert-*` file.

Limit of push trust: every instance this phone is paired with holds the same device token, so a push cannot prove which paired instance sent it. A push names its instance in its payload, and a paired instance can claim another one. The app limits what such a push can do: a thread or task push opens that thread through the link path, and an account or notification push opens only the fixed pages `/settings/claude-accounts` and `/settings/notifications` (`PushDestination.knownWebPaths`). Any other path opens the instance and nothing more. A link from outside the app matches an instance only by the address the app verified at pairing, never by the address the server reports for itself. A Live Activity that a push started names the server's address, so a tap on it for an instance whose reported address differs from the paired one offers pairing instead of opening the thread.

To send a real alert to a simulator, save a payload built from one of those files (set `yak.instance` to the stub's address) as `alert.apns` with a `"Simulator Target Bundle"` entry and run `xcrun simctl push <device> <bundle identifier> alert.apns`.

The launch argument `-yakTestSkipWebSession YES` keeps the system web sign-in sheet closed, so the waiting screen of the typed-address flow can be seen and tested.

## Walkthrough

`bundle exec fastlane walkthrough device:"iPhone 18 Pro" appearance:dark orientation:landscape` runs `WalkthroughTests`, which visits every state, saves a screenshot of each and runs the accessibility audit on it. It is not part of `ui_smoke`. `appearance` is `light` (default), `dark` or `largest-text`; `orientation` is `portrait` (default) or `landscape`; `only:testName` runs one test and adds to the shots already on disk (a full run starts with an empty folder). Screenshots land in `build/screenshots/<device>/<appearance>/<orientation>/`, so runs of different variants do not overwrite each other. Look at them: the audit does not catch a clipped or crowded layout.

## Building your own copy

Each company builds and signs its own app. You do these steps once, in this order. Every value below is an example; use your own and never commit it.

1. Join the Apple Developer Program and accept the current agreements in App Store Connect.
2. In App Store Connect, open Users and Access, Integrations, and create an API key with the Admin role. Download the `.p8` file once and keep it outside the repository, for example in `~/keys/`. Note the key ID and the issuer ID.
3. Create the app record in App Store Connect (My Apps, plus) for your bundle identifier, named like your `YAK_DISPLAY_NAME`. The API cannot create it.
4. Create an APNs key in the developer portal and give it to your Yak instance as the server's push setup describes (the key file on the server and `YAK_APNS_KEY_PATH`). The app cannot receive pushes without it.
5. Copy `Config.example.xcconfig` to `Config.xcconfig` and fill in `YAK_TEAM_ID`, `YAK_BUNDLE_ID`, `YAK_APP_GROUP` and `YAK_DISPLAY_NAME`. A Sentry DSN contains `//`, which starts a comment in this file; write it as `https:/$()/public@host/1` (see the note in the example file).
6. Write the testers file outside the repository: one email address per line, at most 100. Point `YAK_TESTERS_FILE` at it.
7. Run bootstrap three times:
   - `bundle exec fastlane bootstrap dry_run:true` only reads and prints what it would do. Export `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_PATH` (the `.p8` file) and the `YAK_*` values from step 5 first. The lane names every missing value.
   - `bundle exec fastlane bootstrap` registers the three identifiers (`<id>`, `<id>.widgets`, `<id>.share`), enables their capabilities, creates the tester group and adds the testers.
   - Run it once more. Every row should read "already present" except the app group and Broadcast rows, which always read "check by hand".
8. Do each row that bootstrap marks "check by hand" or "needs a person" in the developer portal. Likely:
   - Register the app group and assign it to all three identifiers (the API cannot).
   - Turn on Broadcast Capability under Push Notifications for `<id>`.
   - Push Notifications is enabled on both `<id>` and `<id>.widgets`. The app uses it for alerts and Live Activity tokens. The widget extension carries `aps-environment` for widget pushes, and automatic signing needs the capability on its identifier to make its profile.
   - Turn on Time Sensitive Notifications on `<id>`.
   - Debug builds get sandbox push tokens and Release builds get production tokens. An instance set to `production` pushes only to TestFlight builds, and a Release build run from Xcode gets a sandbox token that Apple refuses.
   - Invite anyone listed as "not an App Store Connect user yet".
9. Create the GitHub environment `testflight` (Settings, Environments), restricted to the `main` branch, with required reviewers if you want them. Add these:

   | Name | Kind | Lives in | Required | Example | What it is |
   |---|---|---|---|---|---|
   | `YAK_TEAM_ID` | variable | environment | yes | `YOUR_TEAM_ID` | Your Apple developer team |
   | `YAK_BUNDLE_ID` | variable | environment | yes | `com.example.yak` | The app's bundle identifier |
   | `YAK_APP_GROUP` | variable | environment | yes | `group.com.example.yak` | The app group shared with the extensions |
   | `YAK_DISPLAY_NAME` | variable | environment | yes | `Yak` | The app's name |
   | `YAK_TESTER_GROUP` | variable | environment | yes | `Yak team` | The TestFlight group that receives each build |
   | `ASC_KEY_ID` | secret | environment | yes | `ABC123DEFG` | The App Store Connect API key ID |
   | `ASC_ISSUER_ID` | secret | environment | yes | a UUID | The key's issuer ID |
   | `ASC_KEY_CONTENT` | secret | environment | yes | base64 of the `.p8` file | The key itself (`base64 -i AuthKey.p8 \| pbcopy`) |
   | `YAK_IOS_RELEASE` | variable | repository | yes | `on` | The release switch (see below) |
   | `YAK_SENTRY_DSN` | variable | environment | no | `https://public@sentry.example.invalid/1` | Turns crash reporting on |
   | `SENTRY_ORG` | variable | environment | no | `example-org` | Sentry organization, for symbol upload |
   | `SENTRY_PROJECT` | variable | environment | no | `yak-ios` | Sentry project, for symbol upload |
   | `SENTRY_AUTH_TOKEN` | secret | environment | no | a Sentry token | Uploads symbol files after each build |
   | `YAK_BUILD_NUMBER_OFFSET` | variable | environment | no | `0` | Added to the run number after you rename the workflow |

   `YAK_IOS_RELEASE` is set under Settings, Secrets and variables, Actions, Variables, not in the environment: a job-level `if` is evaluated before the environment applies and cannot read the environment's variables. Without it a fork skips the release job quietly. With it set and a value missing, the lane stops at its first step and names each missing value. Allow the workflow to push `ios/v*` tags if you protect tags.
10. Run the "iOS release" workflow by hand (Actions, "iOS release", Run workflow, lane `beta`). The job summary shows the version, build number, tester group and tag.
11. Open TestFlight on your iPhone, install the build and open it.

## Releases

- The version lives in `VERSION` and changes by pull request.
- Every merge to `main` that touches `ios/` builds and uploads to TestFlight. There is no tag trigger: the release job writes the tag itself, `ios/v<version>-<build>`, and `refresh` and the release notes read these tags.
- The build number is the workflow's run number (`412`, and `412.2` on a re-run). The lane refuses when that is not above TestFlight's latest build. After you rename the workflow the run numbers restart; set `YAK_BUILD_NUMBER_OFFSET` high enough to stay above the last build.
- TestFlight builds expire after 90 days. On the 1st of every other month the `refresh` lane rebuilds the last released tag, so a build is always available.
- GitHub turns off scheduled workflows in a public repository after 60 days without repository activity. A quiet fork can lose the refresh this way. Whether a tag pushed by the workflow's own `GITHUB_TOKEN` counts as activity is not stated in GitHub's documentation, so do not rely on it; a merge to `main` is the sure way. To check, open the Actions tab: a turned-off workflow shows as disabled. Turn it back on with Actions, "iOS release", "Enable workflow", and do the same for "iOS nightly". A manual run of `refresh` covers a missed one.
- Coming with the walkthrough task (not on this branch yet): `bundle exec fastlane screenshots only:"Class/test"` runs the named walkthrough screens in light and dark and writes `build/screenshots/pr/` with a `body.md`. Attach them to a pull request from that folder with `gh pr edit <number> --body-file body.md --attach './light-name.png#Alt text'`.
- Coming with the walkthrough task: `bundle exec fastlane sweep` runs the whole walkthrough on every device, appearance and orientation and writes a contact sheet at `build/screenshots/sweep/index.html`.
- Coming with the crash reporting task (the Settings footnote and `project-sentry.yml` do not exist yet): crash reporting is compiled in only when `YAK_SENTRY_DSN` is set. It collects crashes, app hangs and watchdog terminations. It never sends messages, thread titles, tokens, breadcrumbs, screenshots or view hierarchies, and the app says so in Settings.
- Dependabot watches GitHub Actions, the Gemfile and the app's Swift packages through `Packages/Pins`. A pull request there fails lint until the matching `exactVersion` in `project.yml` (or `project-sentry.yml`, once the crash reporting branch adds it) changes in the same pull request; change that one line and push.
- Before a phase's TestFlight build is called done, run the manual pass in [docs/manual-pass.md](docs/manual-pass.md).
- Licenses: SwiftTerm (MIT), swift-markdown (Apache 2.0) and sentry-cocoa (MIT). The in-app licenses screen comes with the same task and does not exist yet.
