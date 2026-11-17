import SwiftUI
import YakKit

/// "Send a test notification" with its sending, sent and refused states.
struct TestNotificationRow: View {
    let model: SettingsModel

    var body: some View {
        Section {
            Button {
                Task { await model.sendTest() }
            } label: {
                HStack(spacing: 12) {
                    switch model.test {
                    case .sending:
                        ProgressView().accessibilityHidden(true)
                        Text("settings.test.sending")
                    case .sent:
                        Image(systemName: "checkmark.circle.fill").foregroundStyle(Color(.systemGreen))
                            .accessibilityHidden(true)
                        Text("settings.test.sent")
                    case .idle, .failed:
                        Text("settings.test.send")
                    }
                    Spacer(minLength: 0)
                }
                .frame(minHeight: 44)
                .contentShape(.rect)
            }
            .disabled(model.test == .sending)
            .accessibilityIdentifier("settings.test")
            if case .failed(let problem) = model.test {
                Text(SettingsText.test(problem)).foregroundStyle(Color.yakDanger)
                    .accessibilityIdentifier("settings.test.failed")
            }
        }
        .sensoryFeedback(.error, trigger: model.refusalCount)
    }
}
