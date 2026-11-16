import SwiftUI

struct RootView: View {
    let model: AppModel

    @SceneStorage("yak.scene") private var restoredScene: Data?
    @State private var scene: SceneModel?
    @Environment(\.scenePhase) private var scenePhase

    var body: some View {
        Group {
            if !model.hasLoaded || scene == nil {
                Color(.systemBackground).ignoresSafeArea()
            } else {
                switch model.route {
                case .connect:
                    NavigationStack { ConnectView(model: model) }.linkPairingStatus(model)
                case .signedIn(let instance):
                    if let scene, let session = model.activeSession {
                        InstanceShell(model: model, routedInstance: instance, session: session, scene: scene)
                            .id(instance.id)
                            .linkPairingStatus(model)
                    }
                case .pairAgain(let instance):
                    PairAgainView(model: model, instance: instance).linkPairingStatus(model)
                }
            }
        }
        .sensoryFeedback(.success, trigger: model.linkPairingSuccessCount)
        .animation(.spring, value: model.route)
        .animation(.spring, value: model.linkPairing?.state)
        .task {
            if scene == nil { scene = model.makeSceneModel(restored: restoredScene) }
            await model.reloadRoute()
        }
        .onChange(of: scene?.state) { restoredScene = scene?.encodedState }
        .onChange(of: scenePhase) { _, phase in
            Task {
                switch phase {
                case .background: await model.sessions.enterBackground()
                case .active: await model.sessions.enterForeground()
                default: break
                }
            }
        }
        .onChange(of: model.network.isOnline) { _, isOnline in
            Task { await model.sessions.networkChanged(isOnline: isOnline) }
        }
    }
}
