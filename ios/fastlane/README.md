fastlane documentation
----

# Installation

Make sure you have the latest version of the Xcode command line tools installed:

```sh
xcode-select --install
```

For _fastlane_ installation instructions, see [Installing _fastlane_](https://docs.fastlane.tools/#installing-fastlane)

# Available Actions

## iOS

### ios bootstrap

```sh
[bundle exec] fastlane ios bootstrap
```

One-time App Store Connect setup: identifiers, capabilities, tester group and testers. Option dry_run:true only reads.

### ios beta

```sh
[bundle exec] fastlane ios beta
```

Archive, upload to TestFlight and tag. Option notes: replaces the notes taken from merged pull requests.

### ios refresh

```sh
[bundle exec] fastlane ios refresh
```

Rebuild the last released version so TestFlight keeps an unexpired build.

### ios generate

```sh
[bundle exec] fastlane ios generate
```

Generate the Xcode project from project.yml

### ios lint

```sh
[bundle exec] fastlane ios lint
```

Format check and project rules. The self-test of the rule scripts runs when they changed since it last passed; rules:true or CI always runs it

### ios ui_smoke

```sh
[bundle exec] fastlane ios ui_smoke
```

UI smoke tests against the stub server: fastlane ui_smoke device:"iPhone Duo" only:testName. Builds once and reuses the build while ios/ is unchanged (rebuild:true forces a build). part:1 or part:2 runs one half of the classes, split by test count. Takes one of YAK_SIM_SLOTS simulator slots (default 4). A failed test is retried only on CI or with retry:true. The simulator it booted is shut down afterwards (keep:true leaves it booted)

### ios walkthrough

```sh
[bundle exec] fastlane ios walkthrough
```

Quality-bar walkthrough, screenshots in build/screenshots/<device>/<appearance>/<orientation>: fastlane walkthrough device:"iPhone 18 Pro" appearance:dark|light|largest-text orientation:landscape only:testName. Builds once, takes a simulator slot and retries only on CI, like ui_smoke (rebuild:true, retry:true)

### ios test

```sh
[bundle exec] fastlane ios test
```

YakKit package tests and app unit tests. The app tests build once and reuse the build while ios/ is unchanged (rebuild:true forces a build) and take a simulator slot (YAK_SIM_SLOTS, default 4)

----

This README.md is auto-generated and will be re-generated every time [_fastlane_](https://fastlane.tools) is run.

More information about _fastlane_ can be found on [fastlane.tools](https://fastlane.tools).

The documentation of _fastlane_ can be found on [docs.fastlane.tools](https://docs.fastlane.tools).
