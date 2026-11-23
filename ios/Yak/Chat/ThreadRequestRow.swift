import SwiftUI
import YakKit

/// A web address to show in the in-app browser.
struct BrowserLink: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

/// Under the title: where the request came from, as a chip that opens its page, the request's status as a pill, and the
/// current or failed step with a dot in the status tone.
struct ThreadRequestRow: View {
    let request: ThreadRequest
    /// The current or failed step, such as "Step 5 of 7: CI failed".
    var stepLine: String?
    @State private var browserURL: BrowserLink?
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.verticalSizeClass) private var verticalSizeClass

    var body: some View {
        VStack(alignment: .leading, spacing: Design.Space.compact) {
            ViewThatFits(in: .horizontal) {
                HStack(spacing: Design.Space.compact) {
                    chip
                    pill
                    Spacer(minLength: 0)
                }
                // Beside the chip when they fit; at the accessibility sizes the status pill alone says enough.
                if dynamicTypeSize.isAccessibilitySize {
                    pill.frame(maxWidth: .infinity, alignment: .leading)
                } else {
                    VStack(alignment: .leading, spacing: Design.Space.compact) {
                        chip
                        pill
                    }
                    .frame(maxWidth: .infinity, alignment: .leading)
                }
            }
            // At the accessibility sizes the line would take the room of the chat; the status pill says the same.
            if let stepLine, !dynamicTypeSize.isAccessibilitySize, verticalSizeClass != .compact {
                HStack(spacing: Design.Space.compact) {
                    Circle().fill(request.status.tone.dot).frame(
                        width: Design.Size.statusDot, height: Design.Size.statusDot
                    )
                    .accessibilityHidden(true)
                    Text(verbatim: stepLine).font(.yak(.footnote, .medium)).foregroundStyle(Color.yakCardSecondaryText)
                        .fixedSize(horizontal: false, vertical: true)
                }
                .accessibilityElement(children: .combine).accessibilityIdentifier("thread.step")
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .id(dynamicTypeSize)
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.request")
    }

    private var sourceText: String {
        [request.source.label, request.source.reference].compactMap { $0 }.joined(separator: " ")
    }

    @ViewBuilder private var chip: some View {
        let label = Text(verbatim: sourceText).font(.yak(.footnote, .medium)).foregroundStyle(.primary)
            .padding(.horizontal, Design.Space.snug).padding(.vertical, Design.Space.tight)
            .frame(minHeight: Design.Size.sourceChip)
            .background(Color.yakCardSurface, in: .capsule).fixedSize(horizontal: false, vertical: true)
        if let url = WebLinkRule.safeURL(request.source.url) {
            // The chip is drawn 28 points high; its hit area is 44 and does not add to the row's height.
            Button {
                browserURL = BrowserLink(url: url)
            } label: {
                label.frame(minHeight: Design.Size.control).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .padding(.vertical, -hitInset)
            .accessibilityIdentifier("thread.source")
        } else {
            label.accessibilityIdentifier("thread.source")
        }
    }

    /// How far the 44 point hit area reaches beyond the drawn chip. Above the default text size the chip is as tall as
    /// the hit area, so nothing is taken from the row.
    private var hitInset: CGFloat {
        dynamicTypeSize > .large ? 0 : (Design.Size.control - Design.Size.sourceChip) / 2
    }

    private var pill: some View {
        TonePill(text: request.status.label, tone: request.status.tone).accessibilityIdentifier("thread.status")
    }
}
