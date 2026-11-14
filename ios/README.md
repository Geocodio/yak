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
