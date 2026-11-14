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

`bundle exec fastlane ui_smoke device:"iPhone 18 Pro"` builds `Packages/YakStub`, starts `yak-stub` on a free port, runs `YakUITests` against it and stops it. The stub replays the contract fixtures in `fixtures/`; a test picks the scenario with `StubControl.play(_:)`, which posts the name to `/__stub/scenario`. Scenarios: `paired`, `revoked`, `server-too-new`, `server-too-old`, `not-yak`, `slow` (every answer after 10 seconds), `down` (connections closed), `broken` (500 everywhere), `code-used`, `code-expired`, `approval-pending` (`device/token` answers `authorization_pending` for ever), `approval-denied`, `approval-expired`, `approval-slow-down`. `POST /__stub/online` with `no` or `yes` takes the app offline and back in test mode (a new scenario resets it); `StubControl.setOnline(_:)` does this from a test.

Debug builds understand the launch arguments `-yakTestInstance <url>` (test mode: in-memory storage, plain `http://127.0.0.1` accepted), `-yakTestToken <token>` (start paired with that instance) and `-yakTestKeychainLocked YES` (token reads fail). Release builds contain none of this.
