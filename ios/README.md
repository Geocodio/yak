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

## Licenses

The app target uses one Swift package, [SwiftTerm](https://github.com/migueldeicaza/SwiftTerm) (MIT), for the Terminal pane. It is pinned to an exact version in `project.yml`. The conversation is the server's web page, so the app has no Markdown renderer.

## Running tests fast

Locally, the lanes build once and test many times. `ui_smoke`, `walkthrough` and the app tests of `test` run `xcodebuild build-for-testing` and then `test-without-building` in `build/DerivedData`. The next run skips the build while nothing under `ios/` changed, and the lane output says whether it built or reused. Add `rebuild:true` to force a build. CI always builds.

At most `YAK_SIM_SLOTS` simulator test jobs run at once on a machine (default 4). Each slot is one booted simulator, and a booted simulator costs about a hundred processes and several gigabytes even when idle. 4 is right for a 16-core, 128 GB Mac with other work running. A lane shuts down the simulator it booted when its run ends; one that was already booted stays up, and `keep:true` leaves it booted for many small commands in a row. A job that finds all slots taken prints "waiting for a simulator slot" once a minute and starts when one frees up. The slots are folders in `/tmp/yak-ios-sim-slots`. CI takes no slot.

A failed UI test is not retried locally. Add `retry:true` to get one more try, as CI does.

`lint` runs the self-test of the rule scripts (`test-rules.sh`) only when `scripts/` or the workflow files changed since it last passed. Add `rules:true` to run it anyway. CI always runs it.

Run one test with `only:<Class>/<test>`, for example `bundle exec fastlane ui_smoke only:PairingSmokeTests/testName`.

## UI tests and the stub server

`bundle exec fastlane ui_smoke device:"iPhone 18 Pro"` builds `Packages/YakStub`, starts `yak-stub` on a free port, runs `YakUITests` against it and stops it. The stub replays the contract fixtures in `fixtures/`; a test picks the scenario with `StubControl.play(_:)`, which posts the name to `/__stub/scenario`. Scenarios: `paired`, `revoked`, `server-too-new`, `server-too-old`, `not-yak`, `slow` (every answer after 10 seconds), `slower` (14 seconds, so the slow-connection hint shows before the answer arrives), `down` (connections closed), `broken` (500 everywhere), `code-used`, `code-expired`, `approval-pending` (`device/token` answers `authorization_pending` for ever), `approval-denied`, `approval-expired`, `approval-slow-down`. `POST /__stub/online` with `no` or `yes` takes the app offline and back in test mode (a new scenario resets it); `StubControl.setOnline(_:)` does this from a test.

Debug builds understand the launch arguments `-yakTestInstance <url>` (test mode: in-memory storage, plain `http://127.0.0.1` accepted), `-yakTestToken <token>` (start paired with that instance) and `-yakTestKeychainLocked YES` (token reads fail). Release builds contain none of this.

`bundle exec fastlane ui_smoke only:testName` runs one test of `PairingSmokeTests`. Add `device:"iPhone Duo"` for another simulator.

Features add files and no lines: `ui_smoke` runs every class in `YakUITests/*SmokeTests.swift` and the core classes listed in `CORE_SMOKE_CLASSES` in the Fastfile, `walkthrough` every class in `YakUITests/*WalkthroughTests.swift`, and `only:` takes `Class/test` or a bare test name that must be in exactly one class. A feature smoke test runs on the iPhone row only, and `core_only:true` (the iPad and the Duo rows) runs the core classes, so a feature that wants those rows adds its class there. A feature's project rules live in `scripts/rules.d/<feature>.sh`, which may add to `targets`, `no_rust_folders` and `rust_allowed_files`; its stub helpers go in `StubControl+<Feature>.swift`, which uses `StubControl.post(_:_:)` and `get(_:)`.

Push scenarios (played with `StubControl.play(_:)`): `push-unavailable` (the instance has no notification key), `push-other-build`, `push-other-environment`, `push-registration-failing` (every token registration answers 503), `push-test-rejected` (the test notification is refused) and `notifications-failing` (saving a switch fails). `GET /__stub/push` answers what the stub holds: the registered `tokens` per kind, the reported `activities` and the saved `preferences`; `StubControl.pushState()` reads it. Push launch arguments, all Debug only: `-yakTestNotifications notDetermined|authorized|denied`, `-yakTestNotificationRequest granted|denied|failed|slow`, `-yakTestPushToken fail`, `-yakTestPushBuild <bundle id>/<environment>`, `-yakTestLiveActivities off`, `-yakTestHoldExplanation YES`, `-yakTestPushTap <base64 payload>` (a tap that launched the app) and `-yakTestScreen live-activity-gallery` with the environment variable `YAK_TEST_GALLERY_STATE` (`working`, `working-no-step`, `needs-you`, `finished`, `failed`, `stopped`, `stale`, `unknown-state`, `private`, `long-title`). While the app runs, `yak://test-push?payload=<base64 payload>` taps a push. `StubControl.pushPayload(_:)` builds the payload from a `scenario-push-alert-*` file.

Limit of push trust: every instance this phone is paired with holds the same device token, so a push cannot prove which paired instance sent it. A push names its instance in its payload, and a paired instance can claim another one. The app limits what such a push can do: a thread or task push goes through the link path, which resolves the address it names to a paired instance (a task page opens only on a paired instance, and an unpaired address only offers pairing), and an account or notification push opens only the fixed pages `/settings/claude-accounts` and `/settings/notifications` (`PushDestination.knownWebPaths`). Any other path opens the instance and nothing more. A link from outside the app matches an instance only by the address the app verified at pairing, never by the address the server reports for itself. A Live Activity that a push started names the server's address, so a tap on it for an instance whose reported address differs from the paired one offers pairing instead of opening the thread.

To send a real alert to a simulator, save a payload built from one of those files (set `yak.instance` to the stub's address) as `alert.apns` with a `"Simulator Target Bundle"` entry and run `xcrun simctl push <device> <bundle identifier> alert.apns`.

Thread action scenarios (star, close and delete; the stub edits its list and thread pages by what the app sent, and `/__stub/thread-actions/log` lists the requests): `thread-actions` (every call succeeds), `thread-actions-recipient` (the person may only read: close and delete answer 403 and the rows have `canDelete: false`), `thread-actions-forbidden` (close and delete answer 403), `thread-actions-gone` (every call answers 404), `thread-actions-conflict` (the first delete answers 409 `sandbox_unavailable`, the next succeeds) `thread-actions-single` (one thread alone in the list, so a drawer can be audited whole) and `thread-actions-slow` (close and delete wait until `POST /__stub/thread-actions/release`). `POST /__stub/thread-actions/delete-elsewhere` with a thread id deletes it as another device would.

Drawer tab scenarios: `drawer-tabs` (each filter answers its own rows, the Review answer is the server's `threads-review` fixture, `me` lists all five filters), `drawer-tabs-quiet` (nothing waits in Review, so the tab has no count) `drawer-tabs-older` (`me` has no `threadFilters`, and lists have no review count and no facets) and `drawer-tabs-link` (the Review tab is empty and asks to link GitHub).

Search scenarios (`GET search` as the server answers it: one flat list, ten of each kind at most, 422 under two characters; a query of `retry` finds a thread, a repository and two pull requests, one with a thread and one without): `search`, `search-empty` (finds nothing), `search-slow` (the answer waits for `POST /__stub/search/release`), `search-failing` (500) and `search-limited` (429 with `Retry-After: 2`); the last two answer normally after `POST /__stub/search/relent`. `GET /__stub/search/log` lists the queries asked, one per line; `StubControl.searchLog()`, `releaseSearch()` and `relentSearch()` wrap them.

Review row scenarios: `review-line` (the Review tab with every state of the pull request line, a thread with two pull requests, and a failed row with a long error in Mine), `review-line-few` (three rows, so the whole list fits and an audit sees no row cut at an edge) and `review-line-empty` (nothing in any group).

Widget scenarios: `widgets-busy` and `widgets-empty` (the `mine` thread list is the busy or the empty widget list), `widgets-old-server` (`PUT push-tokens/widget` answers 404) and `widgets-other-environment` (409 `push_environment_mismatch`). `GET /__stub/widgets` answers the widget `tokens` the stub holds and the number of `deletes`; `StubControl.widgetTokens()` reads the tokens. Widget, intent and quick action launch arguments, all Debug only: `-yakTestScreen widget-gallery` with `YAK_TEST_GALLERY_STATE` (`needs-you`, `busy`, `empty`, `private-first`, `long-title`, `huge-count`, `stale`, `no-instance`, `instance-missing`, `needs-pairing`, `not-loaded`, `placeholder`), `-yakTestIntent start-thread|needs-me|open-last` (runs the intent's rules against the stub and shows the sentence in the label `intent.result`) and `-yakTestQuickAction new-thread|open` (delivers the quick action that launched the app). The Home Screen is not driven by a test: what a widget shows is proven by the unit tests of the widget content and by the gallery, which draws every family at its real size.

The launch argument `-yakTestSkipWebSession YES` keeps the system web sign-in sheet closed, so the waiting screen of the typed-address flow can be seen and tested.

Embed scenarios (the thread's conversation as a web page): `embed-basic`, `embed-expired-once` (the first page load answers 401), `embed-slow` (the page says ready after 12 seconds), `embed-failed` (every mint answers 503) and `embed-unsupported` (an older server). `me` carries `web` in every scenario but `embed-unsupported`, so the thread of every test shows this page and `embed-unsupported` shows the "Update needed" state of the Chat pane. The page is a test page that speaks the script bridge, not the real thread page. `GET /__stub/embed/state` also counts `shows`, the times the app fetched a thread. `GET /__stub/embed/state` answers how many codes were minted and redeemed and how many page loads there were, and `POST /__stub/embed/expire` makes the next page load answer 401. The embed test page links to a shared report (`/files/<id>`, a viewer page with its framed report at `/files/<id>/raw`), which needs the embed session cookie like the thread page; `POST /__stub/embed/file/<mode>` with `ok`, `missing` (404), `forbidden` (403), `broken` (500), `attachment` (sent as a download) or `slow` sets how the file pages answer. A file request without the session is redirected to `/login`, as the real server does (only the embed thread page answers 401). The thread test page also links to a second report (`Open scripted report`) whose script clicks a link three seconds after it loads.

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
   - Push Notifications is enabled on both `<id>` and `<id>.widgets`. On `<id>.widgets` it carries the widget push token; keep the app group and the Keychain group on it too. The app uses it for alerts and Live Activity tokens. The widget extension carries `aps-environment` for widget pushes, and automatic signing needs the capability on its identifier to make its profile.
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

## Attachments and the share sheet

A message with files is written as an outbox item, then as an upload record and a multipart body file in the app group. A background `URLSession` sends the body: one session per paired instance in the app, and one per share in the `<bundle id>.share` extension. A failed upload is tried again on the next launch, when the app returns to the foreground, from background refresh, and when the person taps Retry. The extension shares the instance list through the app group's defaults and the token through the Keychain group that both targets list first in their entitlements.

Stub scenarios: `chat-attachments` (files on a message), `upload-fails` (the first upload of each send key is dropped), `upload-slow` and `expired-link`. Launch arguments: `-yakTestKeepFiles <run id>` keeps the test files and the paired instance across a relaunch, and `-yakTestShare <case>` shows the share sheet inside the app with fake items (`ready`, `last-thread`, `private-last-thread`, `no-instance`, `signed-out`, `too-many`, `preparing-slow`, `no-context-offline`, `repository-not-ready`, `saving`, `failed`).

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
- Licenses: SwiftTerm (MIT) and sentry-cocoa (MIT). The in-app licenses screen comes with the same task and does not exist yet.
