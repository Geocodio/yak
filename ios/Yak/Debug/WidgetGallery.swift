#if DEBUG
    import SwiftUI
    import YakKit

    /// One page per widget state: every family at its real size on a dark and a light home screen strip, for
    /// screenshots and the accessibility audit. Debug builds only.
    struct WidgetGallery: View {
        /// `needs-you`, `busy`, `empty`, `private-first`, `long-title`, `huge-count`, `stale`, `no-instance`,
        /// `instance-missing`, `needs-pairing`, `not-loaded` and `placeholder`.
        let stateName: String

        private static let now = Date()
        private static let instance = Instance(
            url: URL(string: "https://yak.example.com")!, name: "Example Yak", userName: "Test person",
            lastUsedAt: now)

        private struct Sample {
            let snapshot: WidgetSnapshot?
            let state: WidgetState
            var isPlaceholder = false
        }

        private static func thread(_ id: String, _ title: String, detail: String? = nil, reason: String? = nil)
            -> WidgetItem
        {
            WidgetItem(
                kind: .thread, id: id, title: title, isPrivate: false, detail: detail,
                lastActivityAt: now.addingTimeInterval(-120), needsYouReason: reason)
        }

        private static var needsYouItems: [WidgetItem] {
            [
                thread(
                    "d0e515ef", "Retry failed rows when the import times out", detail: "Asked you a question",
                    reason: "question"),
                WidgetItem(
                    kind: .task, id: "k3m9p2qa", title: "Review the import job", isPrivate: false,
                    detail: "Finished. Open Yak to see it.", lastActivityAt: now.addingTimeInterval(-900),
                    needsYouReason: "unseen"),
                thread("m4n5p6q7", "Upgrade the SDK", detail: "Blocked, needs you", reason: "blocked"),
            ]
        }

        private static var runningItems: [WidgetItem] {
            [
                thread("r1a2b3c4", "Fix the flaky export test", detail: "Running the test suite"),
                WidgetItem(kind: .thread, id: "a1b2c3d4", title: nil, isPrivate: true, lastActivityAt: now),
                thread("r5d6e7f8", "Speed up the search index"),
            ]
        }

        private func snapshot(
            needsYou: [WidgetItem], running: [WidgetItem], count: Int? = nil, updated: Date = now
        ) -> WidgetSnapshot {
            WidgetSnapshot(
                instanceName: "Example Yak", needsYouCount: count ?? needsYou.count, needsYou: needsYou,
                running: running, updatedAt: updated)
        }

        private var sample: Sample {
            let items = Self.needsYouItems
            switch stateName {
            case "busy":
                let more = items + [Self.thread("z9y8x7w6", "Check the new rate limits", reason: "question")]
                return Sample(snapshot: snapshot(needsYou: more, running: Self.runningItems), state: .content)
            case "empty":
                return Sample(snapshot: snapshot(needsYou: [], running: []), state: .content)
            case "private-first":
                let hidden = WidgetItem(
                    kind: .task, id: "p1p2p3p4", title: nil, isPrivate: true,
                    detail: "Waiting for you", lastActivityAt: Self.now, needsYouReason: "question")
                return Sample(
                    snapshot: snapshot(needsYou: [hidden] + items, running: Self.runningItems), state: .content)
            case "long-title":
                let long = Self.thread(
                    "l0n9t1t1", String(repeating: "Retry failed rows when the import times out. ", count: 5),
                    detail: "Asked you a question", reason: "question")
                return Sample(snapshot: snapshot(needsYou: [long], running: [long]), state: .content)
            case "huge-count":
                return Sample(
                    snapshot: snapshot(needsYou: items, running: Self.runningItems, count: 1_000_000), state: .content)
            case "stale":
                return Sample(
                    snapshot: snapshot(
                        needsYou: items, running: Self.runningItems, updated: Self.now.addingTimeInterval(-7200)),
                    state: .content)
            case "no-instance": return Sample(snapshot: nil, state: .noInstance)
            case "instance-missing": return Sample(snapshot: nil, state: .instanceMissing)
            case "needs-pairing": return Sample(snapshot: nil, state: .needsPairing(host: "yak.example.com"))
            case "not-loaded": return Sample(snapshot: nil, state: .notLoaded(host: "yak.example.com"))
            case "placeholder":
                return Sample(
                    snapshot: snapshot(needsYou: items, running: Self.runningItems), state: .content,
                    isPlaceholder: true)
            default:
                return Sample(snapshot: snapshot(needsYou: items, running: Self.runningItems), state: .content)
            }
        }

        private static let sizes: [(name: String, size: WidgetFamilySize, width: CGFloat, height: CGFloat)] = [
            ("Small", .small, 170, 170), ("Medium", .medium, 364, 170),
            ("Lock screen rectangular", .accessoryRectangular, 170, 76),
            ("Lock screen circular", .accessoryCircular, 76, 76),
        ]

        var body: some View {
            NavigationStack {
                ScrollView {
                    VStack(alignment: .leading, spacing: 24) {
                        strip(title: "Dark home screen", scheme: .dark)
                        strip(title: "Light home screen", scheme: .light)
                    }
                    .padding(16)
                }
                .navigationTitle(stateName)
                .navigationBarTitleDisplayMode(.inline)
            }
        }

        private func strip(title: String, scheme: ColorScheme) -> some View {
            VStack(alignment: .leading, spacing: 12) {
                Text(verbatim: title).font(.yak(.headline))
                ForEach(Self.sizes, id: \.name) { entry in
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: "Needs you, \(entry.name)").font(.yak(.caption)).foregroundStyle(.secondary)
                        tile(entry, kind: .needsYou, scheme: scheme)
                    }
                }
                ForEach(Self.sizes.prefix(2), id: \.name) { entry in
                    VStack(alignment: .leading, spacing: 4) {
                        Text(verbatim: "Running, \(entry.name)").font(.yak(.caption)).foregroundStyle(.secondary)
                        tile(entry, kind: .running, scheme: scheme)
                    }
                }
            }
            .padding(12)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(scheme == .dark ? Color.black : Color(white: 0.85), in: RoundedRectangle(cornerRadius: 16))
            .environment(\.colorScheme, scheme)
        }

        @ViewBuilder
        private func tile(
            _ entry: (name: String, size: WidgetFamilySize, width: CGFloat, height: CGFloat),
            kind: WidgetDisplay.Kind, scheme: ColorScheme
        ) -> some View {
            let current = sample
            let display =
                kind == .needsYou
                ? WidgetDisplay(
                    NeedsYouWidgetContent.make(
                        snapshot: current.snapshot, state: current.state, instance: Self.instance, size: entry.size,
                        now: Self.now))
                : WidgetDisplay(
                    RunningWidgetContent.make(
                        snapshot: current.snapshot, state: current.state, instance: Self.instance, size: entry.size,
                        now: Self.now))
            let isAccessory = entry.size == .accessoryRectangular || entry.size == .accessoryCircular
            WidgetBody(display: display, size: entry.size, now: Self.now)
                .redacted(reason: current.isPlaceholder ? .placeholder : [])
                .padding(isAccessory ? 4 : 14)
                .frame(width: entry.width)
                .frame(minHeight: entry.height)
                .background(
                    isAccessory ? Color.white.opacity(0.12) : WidgetChrome.background,
                    in: RoundedRectangle(cornerRadius: isAccessory ? 12 : 22))
        }
    }
#endif
