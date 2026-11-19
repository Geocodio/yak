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

    var body: some View {
        VStack(alignment: .leading, spacing: Design.Space.compact) {
            ViewThatFits(in: .horizontal) {
                HStack(spacing: Design.Space.compact) {
                    chip
                    pill
                    Spacer(minLength: 0)
                }
                VStack(alignment: .leading, spacing: Design.Space.compact) {
                    chip
                    pill
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            // At the accessibility sizes the line would take the room of the chat; the status pill says the same.
            if let stepLine, !dynamicTypeSize.isAccessibilitySize {
                HStack(spacing: Design.Space.compact) {
                    Circle().fill(request.status.tone.dot).frame(
                        width: Design.Size.statusDot, height: Design.Size.statusDot
                    )
                    .accessibilityHidden(true)
                    Text(verbatim: stepLine).font(.footnote.weight(.medium)).foregroundStyle(Color.yakCardSecondaryText)
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
        let label = Text(verbatim: sourceText).font(.footnote.weight(.medium)).foregroundStyle(.primary)
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
            .padding(.vertical, -(Design.Size.control - Design.Size.sourceChip) / 2)
            .accessibilityIdentifier("thread.source")
        } else {
            label.accessibilityIdentifier("thread.source")
        }
    }

    private var pill: some View {
        TonePill(text: request.status.label, tone: request.status.tone).accessibilityIdentifier("thread.status")
    }
}
