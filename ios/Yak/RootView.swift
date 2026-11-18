import SwiftUI
import YakKit

struct RootView: View {
    let model: AppModel
    /// What this window was asked to show; the window keeps it equal to the thread it shows.
    @Binding var request: WindowRequest?

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
        .environment(\.isNetworkOnline, model.network.isOnline)
        .fullScreenCover(item: explanationBinding) { request in
            NotificationExplanationCover(request: request, model: model)
        }
        .sensoryFeedback(.success, trigger: model.linkPairingSuccessCount)
        .animation(.spring, value: model.route)
        .animation(.spring, value: model.linkPairing?.state)
        .task {
            if scene == nil { scene = model.makeSceneModel(restored: restoredScene, request: request) }
            // The window's phase is recorded when it starts, so a window whose phase never changes still counts for
            // the pause, and a rebuilt window resumes the sessions.
            if let scene { await model.scenePhaseChanged(scene, to: scenePhase) }
            await model.reloadRoute()
        }
        .onOpenURL { url in
            Task {
                if let scene { await model.handle(url: url, in: scene) } else { await model.handle(url: url) }
            }
        }
        .onChange(of: scene?.state) { restoredScene = scene?.encodedState }
        .onChange(of: scene?.windowValue) { if request != scene?.windowValue { request = scene?.windowValue } }
        // The window registers when it is made and leaves when it goes away; a route change rebuilds only the screen
        // inside it. The app pauses its live updates when every window is in the background, not when one is.
        .onChange(of: scenePhase) { _, phase in
            guard let scene else { return }
            Task { await model.scenePhaseChanged(scene, to: phase) }
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
            get: {
                guard let scene else { return nil }
                let explanation = model.push?.explanation
                return model.presentsExplanation(in: scene, isShowing: explanation != nil) ? explanation : nil
            },
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
