import SwiftUI

struct RootView: View {
    let model: AppModel

    var body: some View {
        Group {
            switch model.route {
            case .connect:
                NavigationStack { ConnectView(model: model) }
            case .signedIn(let instance):
                SignedInView(model: model, instance: instance)
            case .pairAgain(let instance):
                PairAgainView(model: model, instance: instance)
            }
        }
        .animation(.spring, value: model.route)
        .task { await model.reloadRoute() }
    }
}
