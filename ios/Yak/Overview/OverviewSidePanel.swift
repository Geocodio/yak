import SwiftUI
import YakKit

/// The Overview beside the conversation on a wide window. It holds the same groups as the pane, in a narrower column.
struct OverviewSidePanel: View {
    let thread: ThreadModel
    let width: Double
    let close: () -> Void

    var body: some View {
        HStack(spacing: 0) {
            Rectangle().fill(Color.primary.opacity(0.15)).frame(width: 1).accessibilityHidden(true)
            OverviewPane(thread: thread, onClosePanel: close).frame(width: width)
        }
    }
}

/// "Overview", the status dot and the activity with who started the thread, and the close button.
struct OverviewPanelHeader: View {
    let content: OverviewContent
    let close: () -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 8) {
            VStack(alignment: .leading, spacing: 4) {
                Text("pane.overview").font(.yak(.headline))
                HStack(spacing: 6) {
                    Circle().fill(dotColor).frame(width: 8, height: 8).accessibilityHidden(true)
                    activity.font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                }
                if let started = PeopleGroupView(people: content.people, now: Date()).startedByText {
                    started.font(.yak(.footnote, .medium)).foregroundStyle(Color.yakMuted)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .accessibilityElement(children: .combine).accessibilityAddTraits(.isHeader)
            Button(action: close) {
                Image(systemName: "xmark").font(.yak(.body, .semibold)).frame(width: 44, height: 44)
                    .contentShape(.rect)
            }
            .buttonStyle(.plain).foregroundStyle(.primary)
            .accessibilityLabel(Text("overview.panel.close"))
            .accessibilityIdentifier("overview.panel.close")
        }
        .padding(.leading, 16).padding(.trailing, 4).padding(.top, 8).padding(.bottom, 4)
    }

    private var dotColor: Color {
        switch content.activity {
        case .working: .green
        case .blocked: .orange
        case .waiting, .idle, .closed: Color(.systemGray2)
        }
    }

    private var activity: Text {
        switch content.activity {
        case .working: Text("overview.activity.working")
        case .waiting: Text("overview.activity.waiting")
        case .idle: Text("overview.activity.idle")
        case .blocked: Text("overview.activity.blocked")
        case .closed: Text("overview.activity.closed")
        }
    }
}
