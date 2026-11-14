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

The launch argument `-yakTestSkipWebSession YES` keeps the system web sign-in sheet closed, so the waiting screen of the typed-address flow can be seen and tested.

## Walkthrough

`bundle exec fastlane walkthrough device:"iPhone 18 Pro" appearance:dark orientation:landscape` runs `WalkthroughTests`, which visits every state, saves a screenshot of each and runs the accessibility audit on it. It is not part of `ui_smoke`. `appearance` is `light` (default), `dark` or `largest-text`; `orientation` is `portrait` (default) or `landscape`; `only:testName` runs one test and adds to the shots already on disk (a full run starts with an empty folder). Screenshots land in `build/screenshots/<device>/<appearance>/<orientation>/`, so runs of different variants do not overwrite each other. Look at them: the audit does not catch a clipped or crowded layout.
