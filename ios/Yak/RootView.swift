import SwiftUI

struct RootView: View {
    let model: AppModel

    var body: some View {
        Group {
            if !model.hasLoaded {
                Color(.systemBackground).ignoresSafeArea()
            } else {
                switch model.route {
                case .connect:
                    // The connect screen is not built while a pairing link covers it: a covered screen stays in
                    // the accessibility tree of a wide screen, and a link replaces whatever was being typed anyway.
                    if model.linkPairing == nil {
                        NavigationStack { ConnectView(model: model) }
                    } else {
                        Color(.systemBackground).ignoresSafeArea()
                    }
                case .signedIn(let instance):
                    SignedInView(model: model, instance: instance)
                case .pairAgain(let instance):
                    PairAgainView(model: model, instance: instance)
                }
            }
        }
        .linkPairingStatus(model)
        .sensoryFeedback(.success, trigger: model.linkPairingSuccessCount)
        .animation(.spring, value: model.route)
        .animation(.spring, value: model.linkPairing?.state)
        .task { await model.reloadRoute() }
    }
}
