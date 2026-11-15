# Manual pass

About 30 minutes. Run it from the TestFlight build before a phase's build is called done. Tick each box; write down what you find.

## Devices

- [ ] A normal iPhone
- [ ] iPhone Duo, outer screen
- [ ] iPhone Duo, inner screen
- [ ] iPhone Duo, half open both ways, in both orientations
- [ ] iPhone Duo, fold and unfold in the middle of a thread
- [ ] iPad with a keyboard and a pointer

## Networks

- [ ] Network Link Conditioner "3G": the main journey still works and shows its loading states
- [ ] Network Link Conditioner "Very Bad Network": same
- [ ] Airplane mode on and off in a thread with an unsent message: the message is kept and sent when the network returns

## Push

- [ ] One real push of each kind arrives and opens the right place
- [ ] A Live Activity from start to end
- [ ] The Live Activity in the Duo's narrow pill

## From phase 6

- [ ] Widgets
- [ ] Share extension from Photos and from Safari
- [ ] Action button
- [ ] Focus filter
- [ ] App lock with Face ID
- [ ] Handoff to a Mac
- [ ] Picture in picture

## Terminal and preview

- [ ] Terminal against the trial host
- [ ] Preview against the trial host

## Accessibility

- [ ] VoiceOver on the main journey
- [ ] The largest text size
- [ ] Light and dark
- [ ] Reduce Motion
- [ ] Reduce Transparency

## Performance targets

Measure on a real device with Instruments. Write the measured value and the device in the last column.

| Mark | Target | How to measure | Measured, device |
|---|---|---|---|
| `cold-start-to-thread` | under 400 ms to a cached thread, on a three-year-old iPhone | Points of Interest track, the interval | |
| `push-tap-to-question` | under 2 seconds on a normal connection | Points of Interest track, the interval | |
| `streaming-scroll` | no dropped frames while text streams | Hitches instrument during the interval | |
| `pane-switch` | under 100 ms | Points of Interest track, the interval | |
| `all-panes-open` | under 300 MB with one thread and all panes open | Allocations instrument at the event | |

## Privacy

- [ ] Xcode Organizer's privacy report for the archive matches the manifests
- [ ] The reason codes are right
- [ ] With Sentry on, force a crash: the Sentry event shows no private text

## Housekeeping

- [ ] Periphery by hand: `periphery scan --project Yak.xcodeproj --schemes Yak --targets Yak` after `bundle exec fastlane generate`. Findings fixed or listed.
- [ ] Open Dependabot pull requests on `Packages/Pins` merged or listed
- [ ] The "iOS release" and "iOS nightly" schedules are still enabled in the Actions tab
- [ ] The newest TestFlight build is under 60 days old

Write the results in the phase's pull request.
