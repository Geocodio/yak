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
        VStack(alignment: .leading, spacing: WorkStyle.headerGap) {
            ViewThatFits(in: .horizontal) {
                HStack(spacing: WorkStyle.headerGap) {
                    chip
                    pill
                    Spacer(minLength: 0)
                }
                VStack(alignment: .leading, spacing: WorkStyle.headerGap) {
                    chip
                    pill
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            if let stepLine {
                HStack(spacing: WorkStyle.headerGap) {
                    Circle().fill(request.status.tone.dot).frame(width: WorkStyle.dotSize, height: WorkStyle.dotSize)
                        .accessibilityHidden(true)
                    Text(verbatim: stepLine).font(.footnote.weight(.medium)).foregroundStyle(WorkColor.secondaryText)
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
            .padding(.horizontal, WorkStyle.pillPaddingHorizontal).padding(.vertical, WorkStyle.chipPaddingVertical)
            .frame(minHeight: WorkStyle.chipHeight)
            .background(WorkColor.chip, in: .capsule).fixedSize(horizontal: false, vertical: true)
        if let url = WebLinkRule.safeURL(request.source.url) {
            // The chip is drawn 28 points high; its hit area is 44 and does not add to the row's height.
            Button {
                browserURL = BrowserLink(url: url)
            } label: {
                label.frame(minHeight: WorkStyle.tapTarget).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .padding(.vertical, -(WorkStyle.tapTarget - WorkStyle.chipHeight) / 2)
            .accessibilityIdentifier("thread.source")
        } else {
            label.accessibilityIdentifier("thread.source")
        }
    }

    private var pill: some View {
        TonePill(text: request.status.label, tone: request.status.tone).accessibilityIdentifier("thread.status")
    }
}
