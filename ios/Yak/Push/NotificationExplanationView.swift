import SwiftUI
import UIKit

/// The screen after a pairing that explains what notifications are for, before the system asks.
struct NotificationExplanationView: View {
    let model: NotificationExplanationModel
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.openURL) private var openURL

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                content
                if dynamicTypeSize.isAccessibilitySize { actions }
            }
            .frame(maxWidth: 460, alignment: .leading)
            .frame(maxWidth: .infinity)
            .padding(24)
        }
        .safeAreaInset(edge: .bottom) {
            if !dynamicTypeSize.isAccessibilitySize {
                actions
                    .frame(maxWidth: 460)
                    .padding(.horizontal, 24)
                    .padding(.vertical, 12)
                    .frame(maxWidth: .infinity)
                    .background(Color.yakBackground)
            }
        }
        .background(Color.yakBackground)
        .sensoryFeedback(.success, trigger: model.phase == .on)
        .sensoryFeedback(.warning, trigger: model.phase == .off(failed: false) || model.phase == .off(failed: true))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("notify.screen")
    }

    private var content: some View {
        VStack(alignment: .leading, spacing: 24) {
            Label {
                Text("notify.paired \(model.request.instanceName)")
                    .font(.subheadline.weight(.medium))
            } icon: {
                Image(systemName: "checkmark.circle.fill").foregroundStyle(Color(.systemGreen))
            }
            .accessibilityElement(children: .combine)
            VStack(alignment: .leading, spacing: 12) {
                Text("notify.title").font(.largeTitle.bold()).accessibilityAddTraits(.isHeader)
                Text("notify.text").font(.body).foregroundStyle(Color.yakMuted)
            }
            VStack(alignment: .leading, spacing: 0) {
                row(color: .yakRust, title: "notify.row.needsYou.title", detail: "notify.row.needsYou.detail")
                Divider().padding(.leading, 36)
                row(color: Color(.systemBlue), title: "notify.row.finished.title", detail: "notify.row.finished.detail")
                Divider().padding(.leading, 36)
                row(color: Color(.systemGreen), title: "notify.row.pullRequest.title", detail: nil)
            }
            .padding(.horizontal, 16)
            .background(Color.yakCard, in: .rect(cornerRadius: 14))
            Text("notify.footnote").font(.footnote).foregroundStyle(Color.yakMuted)
            result
        }
    }

    private func row(color: Color, title: LocalizedStringKey, detail: LocalizedStringKey?) -> some View {
        HStack(alignment: .firstTextBaseline, spacing: 12) {
            Circle().fill(color).frame(width: 10, height: 10).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.body.weight(.medium))
                if let detail { Text(detail).font(.subheadline).foregroundStyle(Color.yakMuted) }
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 12)
        .accessibilityElement(children: .combine)
    }

    /// What happened after the tap: the grant's line, or the denial's explanation.
    @ViewBuilder private var result: some View {
        switch model.phase {
        case .on:
            VStack(alignment: .leading, spacing: 4) {
                Label("notify.on", systemImage: "checkmark.circle.fill")
                    .font(.headline)
                    .symbolRenderingMode(.multicolor)
                if model.isOffline {
                    Text("notify.on.offline").font(.subheadline).foregroundStyle(Color.yakMuted)
                }
            }
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("notify.on")
        case .off(let failed):
            VStack(alignment: .leading, spacing: 4) {
                Text(failed ? "notify.failed.title" : "notify.off.title").font(.headline)
                Text(failed ? "notify.failed.text" : "notify.off.text")
                    .font(.subheadline).foregroundStyle(Color.yakMuted)
            }
            .accessibilityElement(children: .combine)
            .accessibilityIdentifier("notify.off")
        case .asking, .idle:
            EmptyView()
        }
    }

    @ViewBuilder private var actions: some View {
        VStack(spacing: 12) {
            switch model.phase {
            case .idle, .asking:
                Button {
                    Task { await model.turnOn() }
                } label: {
                    if model.phase == .asking {
                        ProgressView().frame(maxWidth: .infinity)
                    } else {
                        Text("notify.turnOn")
                    }
                }
                .buttonStyle(PrimaryButtonStyle())
                .accessibilityIdentifier("notify.turnOn")
                .accessibilityLabel(Text("notify.turnOn"))
                Button("notify.notNow") { model.notNow() }
                    .buttonStyle(.plain)
                    .font(.headline)
                    .frame(maxWidth: .infinity, minHeight: 44)
                    .foregroundStyle(Color.yakAction)
                    .accessibilityIdentifier("notify.notNow")
            case .on:
                EmptyView()
            case .off(let failed):
                if !failed {
                    Button("notify.openSettings") {
                        if let url = URL(string: UIApplication.openNotificationSettingsURLString) { openURL(url) }
                    }
                    .buttonStyle(PrimaryButtonStyle())
                    .accessibilityIdentifier("notify.openSettings")
                }
                Button("notify.continue") { model.finish() }
                    .buttonStyle(SecondaryButtonStyle())
                    .accessibilityIdentifier("notify.continue")
            }
        }
    }
}
