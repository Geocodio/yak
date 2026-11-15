import SwiftUI

/// What is wrong with an instance, in plain words, and the way to try again. Used by the signed-in screen and
/// by the shell when the session cannot start.
struct InstanceProblemView: View {
    let message: LocalizedStringKey
    let retry: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(message)
            Button("common.retry", action: retry).buttonStyle(SecondaryButtonStyle())
        }
        .padding(.vertical, 4)
    }
}
