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
    let open: (URL) -> Void

    var body: some View {
        ViewThatFits(in: .horizontal) {
            HStack(spacing: 8) {
                chip
                pill
                Spacer(minLength: 0)
            }
            VStack(alignment: .leading, spacing: 8) {
                chip
                pill
            }.frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.horizontal, 16).padding(.top, 8)
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
                open(url)
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
