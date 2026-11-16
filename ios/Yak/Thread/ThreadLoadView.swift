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
        case .loading: LoadingBoard(isSlow: false) { loadingLine }
        case .slow: LoadingBoard(isSlow: true) { slowCard }
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
            Fitting(alignment: .center) {
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

    private var loadingLine: some View {
        HStack(spacing: 8) {
            ProgressView().controlSize(.small).accessibilityHidden(true)
            Text("load.loading").font(.subheadline).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
        }
        .frame(maxWidth: .infinity, alignment: .leading).padding(.horizontal, 20)
        .accessibilityElement(children: .combine).accessibilityIdentifier("thread.loading")
    }

    private var retryButton: some View {
        Button(action: retry) { Text("common.retry").fixedSize(horizontal: false, vertical: true) }
            .buttonStyle(.bordered).tint(.primary).controlSize(.large)
            .accessibilityIdentifier("thread.retry")
    }

    /// The explanation under the dimmed bubbles, with Retry beside it where the width or the height is short of room.
    private var slowCard: some View {
        SlowCard(retry: retryButton)
            .padding(.horizontal, 16)
            .accessibilityElement(children: .contain).accessibilityIdentifier("thread.slow")
    }

    private func message<Actions: View>(
        title: LocalizedStringKey, body: LocalizedStringKey, systemImage: String, identifier: String,
        @ViewBuilder actions: () -> Actions
    ) -> some View {
        let actionsView = actions()
        return Fitting(alignment: .center) {
            messageBody(title: title, body: body, systemImage: systemImage, isCompact: false, actions: actionsView)
        } compact: {
            messageBody(title: title, body: body, systemImage: systemImage, isCompact: true, actions: actionsView)
        }
        .accessibilityElement(children: .contain).accessibilityIdentifier(identifier)
    }

    private func messageBody<Actions: View>(
        title: LocalizedStringKey, body: LocalizedStringKey, systemImage: String, isCompact: Bool,
        actions: Actions
    ) -> some View {
        VStack(spacing: isCompact ? 4 : 8) {
            Image(systemName: systemImage).font(isCompact ? .title2 : .largeTitle).foregroundStyle(Color.yakMuted)
                .accessibilityHidden(true).padding(.bottom, isCompact ? 0 : 4)
            Text(title).font(.headline).multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)
            Text(body).font(.subheadline).foregroundStyle(Color.yakMuted).multilineTextAlignment(.center)
                .fixedSize(horizontal: false, vertical: true)
            actions.padding(.top, isCompact ? 4 : 8)
        }
        .padding(isCompact ? 16 : 24).frame(maxWidth: 420).frame(maxWidth: .infinity)
    }
}

/// The explanation and Retry of the slow state.
private struct SlowCard<Retry: View>: View {
    let retry: Retry
    @Environment(\.horizontalSizeClass) private var horizontalSizeClass
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var isInline: Bool {
        !dynamicTypeSize.isAccessibilitySize && (horizontalSizeClass == .regular || verticalSizeClass == .compact)
    }

    var body: some View {
        Group {
            if isInline {
                HStack(spacing: 16) {
                    texts.frame(maxWidth: .infinity, alignment: .leading)
                    retry
                }
            } else {
                VStack(alignment: .leading, spacing: 12) {
                    texts
                    retry
                }
            }
        }
        .padding(16).frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.primary.opacity(0.05), in: .rect(cornerRadius: 18))
    }

    private var texts: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("load.slow.title").font(.headline).fixedSize(horizontal: false, vertical: true)
            Text("load.slow.body").font(.subheadline).foregroundStyle(Color.yakMuted)
                .fixedSize(horizontal: false, vertical: true)
        }
    }
}

/// Content that ends above the bar and scrolls when it is taller than the area, as at the largest text size. A short
/// area (landscape on a phone) or large text shows `compact` instead of `full`. A scrolled area rests on its last
/// line, so the explanation and its action are in view without a scroll.
private struct Fitting<Full: View, Compact: View>: View {
    let alignment: Alignment
    @ViewBuilder let full: Full
    @ViewBuilder let compact: Compact
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @State private var areaHeight: CGFloat = 0

    var body: some View {
        ScrollView {
            Group {
                if verticalSizeClass == .compact || dynamicTypeSize.isAccessibilitySize {
                    // The compact variant caps its text, so its action fits in view at the largest size.
                    compact.dynamicTypeSize(...DynamicTypeSize.accessibility1)
                } else {
                    full
                }
            }
            .frame(maxWidth: .infinity, minHeight: areaHeight, alignment: alignment)
        }
        .onGeometryChange(for: CGFloat.self) {
            $0.size.height
        } action: {
            areaHeight = $0
        }
        .defaultScrollAnchor(alignment == .bottom ? .bottom : .top)
        .scrollBounceBehavior(.basedOnSize)
    }
}

extension Fitting where Compact == Full {
    init(alignment: Alignment, @ViewBuilder content: () -> Full) {
        self.init(alignment: alignment, full: content, compact: content)
    }
}

/// Nothing for the first 300 ms, then the bubbles in the layout of the real conversation with the footer under
/// them, at the bottom where the chat starts. In the slow state the bubbles dim. A short area drops the bubbles
/// and keeps the footer.
private struct LoadingBoard<Footer: View>: View {
    let isSlow: Bool
    @ViewBuilder let footer: Footer
    @State private var isShown = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        Group {
            if isShown || isSlow {
                Fitting(alignment: .bottom) {
                    VStack(spacing: 16) {
                        ThreadSkeleton(isSlow: isSlow)
                        footer
                    }
                } compact: {
                    footer
                }
                .padding(.bottom, 12)
                .transition(.opacity)
            } else {
                Color.clear
            }
        }
        .task {
            guard !isSlow else { return }
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            withAnimation(reduceMotion ? nil : .easeIn(duration: 0.2)) { isShown = true }
        }
    }
}

/// Bubbles in the layout of the real conversation.
struct ThreadSkeleton: View {
    let isSlow: Bool

    var body: some View {
        VStack(spacing: 14) {
            bubble(width: 0.62, lines: 2, isTrailing: true)
            bubble(width: 0.86, lines: 3, isTrailing: false)
            bubble(width: 0.5, lines: 1, isTrailing: false)
            if !isSlow { bubble(width: 0.7, lines: 2, isTrailing: true) }
        }
        .padding(.horizontal, 16)
        .opacity(isSlow ? 0.5 : 1).accessibilityHidden(true)
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
