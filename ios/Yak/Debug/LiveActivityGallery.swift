#if DEBUG
    import ActivityKit
    import SwiftUI
    import YakKit

    /// One screen per Live Activity state on a dark wallpaper, for screenshots: the lock screen card, the compact pair,
    /// the compact pair in the Duo's narrow pill, the expanded layout and the minimal view. Debug builds only.
    struct LiveActivityGallery: View {
        /// `working`, `working-no-step`, `needs-you`, `finished`, `failed`, `stopped`, `stale`, `unknown-state`,
        /// `private` and `long-title`.
        let stateName: String

        static let sampleTitle = "Retry failed rows when the import times out"
        static let host = "yak.example.com"

        private struct Sample {
            let content: ThreadActivityContentState
            let title: String
            let isStale: Bool
        }

        private var sample: Sample {
            let started = Date().addingTimeInterval(-185)
            func content(_ state: ThreadActivityState, step: String = "", isPrivate: Bool = false) -> Sample {
                Sample(
                    content: ThreadActivityContentState(
                        state: state, step: step, turnStartedAt: Self.explicitOffset(started),
                        isPrivate: isPrivate),
                    title: Self.sampleTitle, isStale: false)
            }
            switch stateName {
            case "working-no-step": return content(.working)
            case "needs-you": return content(.needsYou)
            case "finished": return content(.finished)
            case "failed": return content(.failed)
            case "stopped": return content(.stopped)
            case "stale":
                let base = content(.working, step: "Running a command")
                return Sample(content: base.content, title: base.title, isStale: true)
            case "unknown-state": return content(ThreadActivityState(rawValue: "paused"))
            case "private": return content(.working, step: "Reading the notes", isPrivate: true)
            case "long-title":
                let base = content(.working, step: "Running a command")
                return Sample(
                    content: base.content, title: String(repeating: "Retry failed rows. ", count: 7), isStale: false)
            default: return content(.working, step: "Running a command")
            }
        }

        /// `2026-11-16T12:00:00+00:00`, as the server writes the turn's start.
        private static func explicitOffset(_ date: Date) -> String {
            let formatter = ISO8601DateFormatter()
            formatter.timeZone = TimeZone(secondsFromGMT: 0)
            let text = formatter.string(from: date)
            return text.hasSuffix("Z") ? String(text.dropLast()) + "+00:00" : text
        }

        private func look(limited: Bool) -> LiveActivityLook {
            LiveActivityLook.make(
                content: sample.content, title: sample.title, isStale: sample.isStale, isLimitedWidth: limited)
        }

        var body: some View {
            ZStack {
                LinearGradient(
                    colors: [Color(red: 0.08, green: 0.1, blue: 0.2), .black], startPoint: .top, endPoint: .bottom
                )
                .ignoresSafeArea()
                ScrollView {
                    VStack(alignment: .leading, spacing: 22) {
                        Text(verbatim: stateName).font(.title2.weight(.semibold)).foregroundStyle(.white)
                            .accessibilityIdentifier("gallery.state")
                        section("Lock screen") {
                            ThreadActivityLockScreenView(look: look(limited: false), host: Self.host)
                                .clipShape(RoundedRectangle(cornerRadius: 22))
                        }
                        section("Compact") { compact(limited: false) }
                        section("Compact, limited width (Duo outer screen)") { narrowPill }
                        section("Expanded") { expanded }
                        section("Minimal") {
                            ThreadActivityMinimal(look: look(limited: false))
                                .frame(width: 44, height: 36).background(Color.black, in: Capsule())
                        }
                        Button {
                            Self.startSample(sample)
                        } label: {
                            Text(verbatim: "Start a sample activity")
                        }
                        .buttonStyle(.borderedProminent)
                        .accessibilityIdentifier("gallery.startSample")
                    }
                    .padding(16)
                    .frame(maxWidth: 520, alignment: .leading)
                    .frame(maxWidth: .infinity)
                }
            }
            .environment(\.colorScheme, .dark)
        }

        private func section(_ name: String, @ViewBuilder content: () -> some View) -> some View {
            VStack(alignment: .leading, spacing: 8) {
                Text(verbatim: name).font(.footnote.weight(.semibold)).foregroundStyle(Color.white.opacity(0.85))
                content()
            }
        }

        private func compact(limited: Bool) -> some View {
            let current = look(limited: limited)
            return HStack(spacing: 8) {
                ThreadActivityCompactLeading(look: current)
                Spacer(minLength: 24)
                ThreadActivityCompactTrailing(look: current)
            }
            .padding(.horizontal, 14).frame(width: 180, height: 36)
            .background(Color.black, in: Capsule())
        }

        /// The Duo's outer screen: a vertical pill 62 points wide, the glyph over at most three characters.
        private var narrowPill: some View {
            let current = look(limited: true)
            return VStack(spacing: 4) {
                ThreadActivityCompactLeading(look: current)
                ThreadActivityCompactTrailing(look: current)
            }
            .frame(width: 62, height: 64).background(Color.black, in: RoundedRectangle(cornerRadius: 31))
        }

        private var expanded: some View {
            let current = look(limited: false)
            return VStack(alignment: .leading, spacing: 8) {
                HStack(alignment: .top) {
                    ThreadActivityExpandedLeading(look: current)
                    Spacer()
                    ThreadActivityExpandedTrailing(look: current)
                }
                ThreadActivityExpandedCenter(look: current)
                ThreadActivityExpandedBottom(look: current)
            }
            .foregroundStyle(.white)
            .padding(16).background(Color.black, in: RoundedRectangle(cornerRadius: 36))
        }

        /// Starts an activity without a push channel, so the lock screen and the Dynamic Island can be looked at.
        @MainActor private static func startSample(_ sample: Sample) {
            let attributes = ThreadActivityAttributes(
                instance: "https://\(host)", threadId: "d0e515ef", title: sample.title)
            _ = try? Activity.request(
                attributes: attributes, content: ActivityContent(state: sample.content, staleDate: nil), pushType: nil)
        }
    }
#endif
