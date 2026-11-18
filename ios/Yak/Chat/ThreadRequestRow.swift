import SwiftUI
import YakKit

/// A web address to show in the in-app browser.
struct BrowserLink: Identifiable {
    let url: URL
    var id: String { url.absoluteString }
}

/// Under the title: where the request came from, as a chip that opens its page, and the request's status as a pill.
struct ThreadRequestRow: View {
    let request: ThreadRequest
    /// The current or failed step, such as "Step 5 of 7: CI failed".
    var stepLine: String?
    @State private var browserURL: BrowserLink?
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            ViewThatFits(in: .horizontal) {
                HStack(spacing: 8) {
                    chip
                    pill
                    Spacer(minLength: 0)
                }
                VStack(alignment: .leading, spacing: 8) {
                    chip
                    pill
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let stepLine {
                Text(verbatim: stepLine).font(.footnote.weight(.medium)).foregroundStyle(.primary)
                    .fixedSize(horizontal: false, vertical: true).accessibilityIdentifier("thread.step")
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .id(dynamicTypeSize)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.request")
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("thread.request")
    }

    private var sourceText: String {
        [request.source.label, request.source.reference].compactMap { $0 }.joined(separator: " ")
    }

    @ViewBuilder private var chip: some View {
        let label = Text(verbatim: sourceText).font(.caption.weight(.semibold)).foregroundStyle(.primary)
            .padding(.horizontal, 10).padding(.vertical, 4)
            .background(Color(.tertiarySystemFill), in: .capsule).fixedSize(horizontal: false, vertical: true)
        if let url = WebLinkRule.safeURL(request.source.url) {
            Button {
                browserURL = BrowserLink(url: url)
            } label: {
                label.frame(minHeight: 44).contentShape(.rect)
            }.buttonStyle(.plain).accessibilityIdentifier("thread.source")
        } else {
            label.accessibilityIdentifier("thread.source")
        }
    }

    private var pill: some View {
        TonePill(text: request.status.label, tone: request.status.tone).accessibilityIdentifier("thread.status")
    }
}
