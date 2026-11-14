import SwiftUI
import YakKit

/// One screen per pairing problem. The typed address stays visible so nothing is lost.
struct PairingProblemView: View {
    let problem: PairingProblem
    let address: String
    let retry: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Image(systemName: symbol)
                .font(.system(size: 40, weight: .light))
                .foregroundStyle(.secondary)
                .accessibilityHidden(true)
            Text(LocalizedStringKey(problem.titleKey)).font(.title2.bold())
            Text(LocalizedStringKey(problem.bodyKey)).font(.body).foregroundStyle(.secondary)
            if !address.isEmpty {
                Text(address)
                    .font(.subheadline.monospaced())
                    .padding(12)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(.quaternary, in: .rect(cornerRadius: 10))
                    .accessibilityLabel(Text("problem.address \(address)"))
            }
            Button("problem.tryAgain", action: retry).buttonStyle(SecondaryButtonStyle())
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .accessibilityElement(children: .contain)
    }

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
