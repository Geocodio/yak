import SwiftUI
import YakKit

/// The request: where it came from, who started it, who is responsible and its status.
struct RequestGroupView: View {
    let request: ThreadRequest
    let open: (URL) -> Void

    var body: some View {
        OverviewGroupCard(kind: .request, title: "overview.group.request") {
            row("overview.request.source") { source }
            RowDivider()
            row("overview.request.startedBy") { Text(verbatim: request.starter.name) }
            RowDivider()
            row("overview.request.responsible") {
                if let name = request.responsibleName {
                    Text(verbatim: name)
                } else {
                    Text("overview.request.nobody").foregroundStyle(Color.yakMuted)
                }
            }
            RowDivider()
            row("overview.request.status") {
                TonePill(text: request.status.label, tone: request.status.tone)
            }
        }
    }

    private func row<Value: View>(_ title: LocalizedStringKey, @ViewBuilder value: () -> Value) -> some View {
        OverviewRow(title: Text(title)) {
            EmptyView()
        } trailing: {
            value().font(.body).multilineTextAlignment(.trailing)
        }
    }

    @ViewBuilder private var source: some View {
        let text = [request.source.label, request.source.reference].compactMap { $0 }.joined(separator: " ")
        if let url = WebLinkRule.safeURL(request.source.url) {
            Button {
                open(url)
            } label: {
                Text(verbatim: text).frame(minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain).foregroundStyle(Color.yakAction).accessibilityIdentifier("overview.request.sourceLink")
        } else {
            Text(verbatim: text)
        }
    }
}

/// The steps of the request, in the server's order: done, current, failed or still to come.
struct ProgressGroupView: View {
    let progress: ThreadProgress

    var body: some View {
        OverviewGroupCard(kind: .progress, title: "overview.group.progress") {
            ForEach(Array(progress.steps.enumerated()), id: \.element.id) { index, step in
                if index > 0 { RowDivider() }
                OverviewRow(
                    title: Text(verbatim: step.label).fontWeight(step.state == .upcoming ? .regular : .semibold)
                ) {
                    if !step.tooltip.isEmpty { Text(verbatim: step.tooltip) }
                } trailing: {
                    TonePill(text: Self.stateText(step.state), tone: Self.stateTone(step.state))
                }
                .accessibilityIdentifier("overview.progress.\(step.key.rawValue)")
            }
        }
    }

    private static func stateText(_ state: StepState) -> String {
        switch state {
        case .done: String(localized: "overview.progress.done")
        case .current: String(localized: "overview.progress.current")
        case .failed: String(localized: "overview.progress.failed")
        default: String(localized: "overview.progress.upcoming")
        }
    }

    private static func stateTone(_ state: StepState) -> Tone {
        switch state {
        case .done: .ok
        case .current: .warn
        case .failed: .bad
        default: .neutral
        }
    }
}
