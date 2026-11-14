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

### ios generate

```sh
[bundle exec] fastlane ios generate
```

Generate the Xcode project from project.yml

### ios lint

```sh
[bundle exec] fastlane ios lint
```

Format check and project rules

### ios ui_smoke

```sh
[bundle exec] fastlane ios ui_smoke
```

UI smoke tests against the stub server: fastlane ui_smoke device:"iPhone Duo"

### ios test

```sh
[bundle exec] fastlane ios test
```

YakKit package tests and app unit tests

----

This README.md is auto-generated and will be re-generated every time [_fastlane_](https://fastlane.tools) is run.

More information about _fastlane_ can be found on [fastlane.tools](https://fastlane.tools).

The documentation of _fastlane_ can be found on [docs.fastlane.tools](https://docs.fastlane.tools).
