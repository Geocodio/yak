import SwiftUI
import YakKit

/// The four notification switches for the active instance, in every state of loading and saving.
struct NotificationSwitchesView: View {
    let model: SettingsModel
    @State private var showsSkeleton = false

    var body: some View {
        SettingsHeaderRow(
            Text("settings.switches.header \(model.active.name)"),
            note: model.canPush ? Text("settings.switches.footer") : nil, alert: saveFailure)
        Section {
            if !model.canPush {
                unavailable
            } else {
                status
                switchRows
            }
        }
        .task(id: isLoading) {
            showsSkeleton = false
            guard isLoading else { return }
            do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
            showsSkeleton = true
        }
    }

    private var isLoading: Bool {
        if case .loading = model.switches.load { return true }
        return false
    }

    private var unavailable: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text("settings.switches.unavailable \(model.active.name)")
            Text(model.unavailableReason).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("settings.switches.unavailable")
    }

    private var switchRows: some View {
        ForEach(PushEvent.switches, id: \.rawValue) { event in
            let isSaving = model.switches.isSaving(event)
            Toggle(
                isOn: Binding(
                    get: { model.switches.isOn(event) },
                    set: { isOn in Task { await model.toggle(event, to: isOn) } })
            ) {
                HStack(spacing: 8) {
                    Text(label(for: event))
                    if isSaving { ProgressView().controlSize(.small).accessibilityHidden(true) }
                }
            }
            .tint(Color.yakAction)
            .disabled(isDisabled || isSaving)
            .frame(minHeight: 44)
            .redacted(reason: showsSkeleton && isLoading ? .placeholder : [])
            .accessibilityHint(isSaving ? Text("settings.saving") : Text(verbatim: ""))
            .accessibilityIdentifier("settings.switch.\(event.rawValue)")
        }
    }

    private var isDisabled: Bool {
        if case .loaded = model.switches.load { return false }
        return true
    }

    /// The failed save, in the heading's group: a row that appears below the switches is under the fold.
    private var saveFailure: Text? {
        guard let problem = model.switches.problem else { return nil }
        return Text("settings.switches.saveFailed \(label(for: problem.event)) \(SettingsText.reason(problem.problem))")
    }

    @ViewBuilder private var status: some View {
        if case .failed(let problem) = model.switches.load {
            VStack(alignment: .leading, spacing: 12) {
                Text(problem == .offline ? "settings.switches.offline" : "settings.switches.loadFailed")
                if problem != .offline {
                    Text(SettingsText.reason(problem)).font(.footnote.weight(.medium))
                        .foregroundStyle(Color.yakMuted)
                    Button("common.retry") { Task { await model.retry() } }.buttonStyle(SecondaryButtonStyle())
                }
            }
            .accessibilityIdentifier("settings.switches.error")
        } else if model.isSlow {
            VStack(alignment: .leading, spacing: 12) {
                Text("settings.switches.slow")
                Button("common.retry") { Task { await model.retry() } }.buttonStyle(SecondaryButtonStyle())
            }
            .accessibilityIdentifier("settings.switches.slow")
        }
    }

    private func label(for event: PushEvent) -> String {
        switch event {
        case .needsYou: String(localized: "settings.switch.needsYou")
        case .turnFinished: String(localized: "settings.switch.turnFinished")
        case .pullRequest: String(localized: "settings.switch.pullRequest")
        case .stackUsage: String(localized: "settings.switch.stackUsage")
        default: event.rawValue
        }
    }
}

/// A heading above a group, as a row of its own so its color and size are ours: a system section header's gray
/// is too light for the accessibility audit.
struct SettingsHeaderRow: View {
    let text: Text
    let note: Text?
    let alert: Text?

    init(_ text: Text, note: Text? = nil, alert: Text? = nil) {
        self.text = text
        self.note = note
        self.alert = alert
    }

    var body: some View {
        Section {
            VStack(alignment: .leading, spacing: 4) {
                text.font(.subheadline.weight(.semibold)).accessibilityAddTraits(.isHeader)
                if let note { note.font(.footnote.weight(.medium)) }
                if let alert {
                    alert.font(.footnote.weight(.medium)).foregroundStyle(Color.yakDanger)
                        .accessibilityIdentifier("settings.switches.saveFailed")
                }
            }
            .foregroundStyle(Color.primary)
            .listRowBackground(Color.clear)
            .listRowInsets(EdgeInsets(top: 12, leading: 16, bottom: 0, trailing: 16))
        }
        .listSectionSpacing(0)
    }
}
