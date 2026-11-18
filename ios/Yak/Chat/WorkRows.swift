import SwiftUI
import YakKit

/// A one-line event of the work, such as "CI passed". A milestone with a safe address opens it in the browser.
struct MilestoneRow: View {
    let milestone: ThreadMilestone
    @State private var browserURL: BrowserLink?

    var body: some View {
        let pill = TonePill(text: milestone.label, tone: milestone.tone)
        Group {
            if let url = WebLinkRule.safeURL(milestone.url) {
                Button {
                    browserURL = BrowserLink(url: url)
                } label: {
                    HStack(spacing: 6) {
                        pill
                        Image(systemName: "arrow.up.right").font(.caption2.weight(.semibold)).accessibilityHidden(true)
                    }
                    .frame(minHeight: 44).contentShape(.rect)
                }
                .buttonStyle(.plain)
                .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
            } else {
                pill
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityIdentifier("thread.milestone.\(milestone.key.rawValue)")
    }
}

/// A retry run has no text: a divider says that Yak tried again.
struct RetryDivider: View {
    let message: ThreadMessage

    var body: some View {
        HStack(spacing: 10) {
            line
            Group {
                if let number = message.retryNumber { Text("chat.retry.number \(number)") } else { Text("chat.retry") }
            }
            .font(.caption.weight(.semibold)).foregroundStyle(Color.yakMuted).fixedSize()
            line
        }
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("chat.retry")
    }

    private var line: some View { Rectangle().fill(Color.primary.opacity(0.18)).frame(height: 1) }
}

/// One answer of a thread made from an old task, rendered by the server.
struct LegacyAnswerView: View {
    let answer: LegacyAnswer
    let thread: ThreadModel
    @State private var browserURL: BrowserLink?

    var body: some View {
        TaskEntryView(
            entry: TaskConversationEntry(kind: "yak", bodyHtml: answer.html),
            openLink: { browserURL = BrowserLink(url: $0) }
        )
        .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
        .accessibilityIdentifier("chat.legacyAnswer")
    }
}

/// Under the name of a person's message: the channel it came from, with a link to it.
struct ViaCaption: View {
    let via: MessageVia
    let isReply: Bool
    @State private var browserURL: BrowserLink?

    private var detail: String { [via.label, via.reference].compactMap { $0 }.joined(separator: " ") }

    var body: some View {
        let text = isReply ? Text("chat.message.replyFrom \(detail)") : Text("chat.message.via \(detail)")
        Group {
            if let url = WebLinkRule.safeURL(via.url) {
                Button {
                    browserURL = BrowserLink(url: url)
                } label: {
                    text.frame(minHeight: 44).contentShape(.rect)
                }
                .buttonStyle(.plain)
                .sheet(item: $browserURL) { BrowserSheet(url: $0.url).ignoresSafeArea() }
            } else {
                text
            }
        }
        .font(.caption.weight(.medium)).foregroundStyle(Color.yakMuted)
        .accessibilityIdentifier("chat.message.via")
    }
}
