import SwiftUI
import YakKit

/// What the conversation area shows while the thread is not ready: skeleton bubbles after 300 ms, the slow line
/// with Retry, and the reasons a thread cannot be shown.
struct ThreadLoadView: View {
    let presentation: ThreadLoadPresentation
    let retry: () -> Void
    let openThreads: () -> Void

    var body: some View {
        switch presentation {
        case .conversation, .sessionOwns: Color.clear
        case .loading:
            ScrollingArea(alignment: .top) { ThreadSkeleton(isSlow: false) }
        case .slow:
            ScrollingArea(alignment: .top) {
                VStack(spacing: 16) {
                    ThreadSkeleton(isSlow: true)
                    slowCard
                }
            }
        case .notFound:
            message(
                title: "load.notFound.title", body: "load.notFound.body", systemImage: "questionmark.folder",
                identifier: "thread.notFound"
            ) {
                Button(action: openThreads) { Text("load.openThreads").fixedSize(horizontal: false, vertical: true) }
                    .buttonStyle(.bordered).tint(.primary).controlSize(.large)
                    .accessibilityIdentifier("thread.openThreads")
            }
        case .offlineWithoutCopy:
            message(
                title: "load.offline.title", body: "load.offline.body", systemImage: "wifi.slash",
                identifier: "thread.offline"
            ) { EmptyView() }
        case .serverError(let text):
            ScrollingArea(alignment: .center) {
                VStack(spacing: 12) {
                    Image(systemName: "exclamationmark.triangle").font(.largeTitle).foregroundStyle(Color.yakMuted)
                        .accessibilityHidden(true)
                    Text(text).multilineTextAlignment(.center).fixedSize(horizontal: false, vertical: true)
                    retryButton
                }
                .padding(24).frame(maxWidth: 420).frame(maxWidth: .infinity)
                .accessibilityElement(children: .contain).accessibilityIdentifier("thread.error")
            }
        }
    }

    private var retryButton: some View {
        Button(action: retry) { Text("common.retry").fixedSize(horizontal: false, vertical: true) }
            .buttonStyle(.bordered).tint(.primary).controlSize(.large)
            .accessibilityIdentifier("thread.retry")
    }

    /// The explanation right under the dimmed skeleton, with Retry beside it where the width allows.
    private var slowCard: some View {
        ViewThatFits(in: .horizontal) {
            HStack(alignment: .center, spacing: 12) {
                slowTexts
                retryButton
            }
            VStack(alignment: .leading, spacing: 12) {
                slowTexts
                retryButton
            }
        }
        .padding(16).frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.primary.opacity(0.05), in: .rect(cornerRadius: 18))
        .padding(.horizontal, 16)
        .accessibilityElement(children: .contain).accessibilityIdentifier("thread.slow")
    }

    private var slowTexts: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("load.slow.title").font(.headline).fixedSize(horizontal: false, vertical: true)
            Text("load.slow.body").font(.subheadline).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
        }
    }

    private func message<Actions: View>(
        title: LocalizedStringKey, body: LocalizedStringKey, systemImage: String, identifier: String,
        @ViewBuilder actions: () -> Actions
    ) -> some View {
        ScrollingArea(alignment: .center) {
            VStack(spacing: 8) {
                Image(systemName: systemImage).font(.largeTitle).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true).padding(.bottom, 4)
                Text(title).font(.headline).multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                Text(body).font(.subheadline).foregroundStyle(Color.yakMuted).multilineTextAlignment(.center)
                    .fixedSize(horizontal: false, vertical: true)
                actions().padding(.top, 8)
            }
            .padding(24).frame(maxWidth: 420).frame(maxWidth: .infinity)
            .accessibilityElement(children: .contain).accessibilityIdentifier(identifier)
        }
    }
}

/// Fills the area above the bar and scrolls when the content is taller than it, as at the largest text size. The
/// content starts below the top row, and a short one sits at the top or the middle as `alignment` says.
private struct ScrollingArea<Content: View>: View {
    let alignment: Alignment
    @ViewBuilder let content: Content

    var body: some View {
        GeometryReader { proxy in
            ScrollView {
                content.frame(maxWidth: .infinity, minHeight: proxy.size.height, alignment: alignment)
            }
            .scrollBounceBehavior(.basedOnSize)
        }
    }
}

/// Nothing for the first 300 ms, then the loading line and bubbles in the layout of the real conversation. In the
/// slow state the bubbles stay and dim, and the explanation sits under them.
struct ThreadSkeleton: View {
    let isSlow: Bool
    @State private var isShown = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Group {
            if isShown || isSlow { content }
        }
        .frame(maxWidth: .infinity, alignment: .top)
        .task {
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            withAnimation(reduceMotion ? nil : .easeIn(duration: 0.2)) { isShown = true }
        }
    }

    @ViewBuilder private var content: some View {
        VStack(spacing: 14) {
            if !isSlow {
                HStack(spacing: 8) {
                    ProgressView().controlSize(.small)
                    Text("load.loading").font(.subheadline).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 4)
            }
            bubbles.opacity(isSlow ? 0.5 : 1).accessibilityHidden(true)
        }
        .padding(.horizontal, 16).padding(.top, 16)
        .transition(.opacity)
        .accessibilityElement(children: isSlow ? .ignore : .combine)
        .accessibilityHidden(isSlow)
        .accessibilityIdentifier(isSlow ? "thread.slowSkeleton" : "thread.loading")
    }

    private var bubbles: some View {
        VStack(spacing: 14) {
            bubble(width: 0.62, lines: 2, isTrailing: true)
            bubble(width: 0.86, lines: 3, isTrailing: false)
            bubble(width: 0.5, lines: 1, isTrailing: false)
            if !isSlow { bubble(width: 0.7, lines: 2, isTrailing: true) }
        }
    }

    private func bubble(width: CGFloat, lines: Int, isTrailing: Bool) -> some View {
        HStack {
            if isTrailing { Spacer(minLength: 40) }
            VStack(alignment: .leading, spacing: 8) {
                ForEach(0..<lines, id: \.self) { index in
                    RoundedRectangle(cornerRadius: 5).fill(Color.primary.opacity(0.1)).frame(height: 13)
                        .frame(maxWidth: index == lines - 1 && lines > 1 ? 120 : .infinity, alignment: .leading)
                }
            }
            .padding(14)
            .frame(maxWidth: 560 * width + 40, alignment: .leading)
            .background(Color.primary.opacity(0.05), in: .rect(cornerRadius: 18))
            if !isTrailing { Spacer(minLength: 40) }
        }
    }
}
