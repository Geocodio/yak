import SwiftUI
import YakKit

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
        .fullScreenCover(item: explanationBinding) { request in
            NotificationExplanationCover(request: request, model: model)
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

extension RootView {
    /// The explanation screen after a pairing, shown over whatever the route shows.
    fileprivate var explanationBinding: Binding<NotificationExplanationRequest?> {
        Binding(
            get: { model.push?.explanation },
            set: { if $0 == nil { model.push?.explanation = nil } })
    }
}

/// Builds the explanation's model once per request, so a redraw keeps the phase.
private struct NotificationExplanationCover: View {
    let request: NotificationExplanationRequest
    let model: AppModel
    @State private var explanationModel: NotificationExplanationModel?

    var body: some View {
        Group {
            if let explanationModel {
                NotificationExplanationView(model: explanationModel)
            } else {
                Color(.systemBackground)
            }
        }
        .onAppear {
            guard explanationModel == nil, let push = model.push else { return }
            explanationModel = NotificationExplanationModel(
                request: request, push: push, isOnline: { model.network.isOnline }, clock: SystemClock())
        }
    }
}
