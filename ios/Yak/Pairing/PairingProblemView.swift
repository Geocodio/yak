import SwiftUI
import YakKit

/// One screen per pairing problem. The typed address stays visible so nothing is lost.
struct PairingProblemView: View {
    let problem: PairingProblem
    let address: String
    /// The typed-address flow has no code from a computer, so an expiry there has its own wording.
    var isTypedAddress = false
    /// Nil hides the button, for a problem that trying again cannot fix.
    let retry: (() -> Void)?

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Image(systemName: symbol)
                .font(.system(size: 40, weight: .light))
                .foregroundStyle(Color.yakMuted)
                .accessibilityHidden(true)
            Text(LocalizedStringKey(titleKey)).font(.title2.bold())
            Text(LocalizedStringKey(bodyKey)).font(.body).foregroundStyle(Color.yakMuted)
            if !address.isEmpty {
                Text(address)
                    .font(.subheadline.monospaced())
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(.quaternary, in: .rect(cornerRadius: 10))
                    .accessibilityLabel(Text("problem.address \(address)"))
            }
            if let retry {
                Button("problem.tryAgain", action: retry).buttonStyle(SecondaryButtonStyle())
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .contain)
    }

    private var usesApprovalWording: Bool { isTypedAddress && problem == .codeExpired }
    private var titleKey: String { usesApprovalWording ? "problem.approvalExpired.title" : problem.titleKey }
    private var bodyKey: String { usesApprovalWording ? "problem.approvalExpired.body" : problem.bodyKey }

    private var symbol: String {
        switch problem {
        case .unreachable: "wifi.slash"
        case .serverTooOld, .appTooOld: "arrow.down.circle"
        case .codeExpired: "clock"
        case .denied: "hand.raised"
        default: "exclamationmark.circle"
        }
    }
}
