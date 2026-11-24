import SwiftUI
import YakKit

/// Prepares the web conversation of the thread that is likely to open next while the drawer is on screen or the app
/// comes to the front, so a tap on a row finds the page loading or ready. This only gathers the facts; the rules are
/// `WebPrewarmPolicy`'s, which the model asks.
struct WebPrewarm: ViewModifier {
    let model: AppModel
    let webConversation: WebConversationModel
    let session: InstanceSession
    let scene: SceneModel
    let isSessionActive: Bool
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.inputBlocked) private var inputBlocked
    @Environment(\.previewDefaults) private var defaults
    @Environment(SystemFeatures.self) private var system
    /// What the server's `me` says about the page; nil until it has answered.
    @State private var offersPage: Bool?

    /// Everything the rules read, so the task runs again when one of them changes, such as a `me` that arrives late.
    private struct Key: Hashable {
        let isActive: Bool
        let isLocked: Bool
        let isSessionActive: Bool
        let isOnline: Bool
        let isLowDataMode: Bool
        let threadID: String?
        let offersPage: Bool?
    }

    private var key: Key {
        Key(
            isActive: scenePhase == .active,
            isLocked: inputBlocked || system.appLock.coversContent(sceneIsActive: scenePhase == .active),
            isSessionActive: isSessionActive, isOnline: model.network.isOnline,
            isLowDataMode: model.network.isConstrained, threadID: scene.state.threadID, offersPage: offersPage)
    }

    private var isTurnedOff: Bool {
        #if DEBUG
            TestMode.value("yakTestPrewarm") == "off"
        #else
            false
        #endif
    }

    func body(content: Content) -> some View {
        content
            .task(id: isSessionActive) {
                guard isSessionActive else { return }
                offersPage = await session.me()?.supportsEmbeddedThread
            }
            .task(id: key) { await prewarm(key) }
    }

    private func prewarm(_ key: Key) async {
        guard !isTurnedOff, let offersPage else { return }
        webConversation.configure(supportsEmbeddedThread: offersPage)
        // The thread the window shows or is about to show, else the last one opened.
        let candidate: String?
        if let shown = key.threadID {
            candidate = shown
        } else {
            candidate = await session.instanceCache()?.lastOpenedThreadID()
        }
        guard !Task.isCancelled else { return }
        webConversation.makePage = { [defaults, session] model in
            WebConversationPageView(
                model: model,
                dataStore: PreviewWebData.store(for: session.instance.id, purpose: .embed, defaults: defaults),
                version: session.appVersion)
        }
        webConversation.prewarm(
            WebPrewarmPolicy(
                isActive: key.isActive, isLocked: key.isLocked, isSessionActive: key.isSessionActive,
                isOnline: key.isOnline, isLowDataMode: key.isLowDataMode, serverOffersPage: offersPage,
                hasPreparedPage: false, candidateThreadID: candidate))
    }
}

extension View {
    func webPrewarm(
        model: AppModel, webConversation: WebConversationModel, session: InstanceSession, scene: SceneModel,
        isSessionActive: Bool
    ) -> some View {
        modifier(
            WebPrewarm(
                model: model, webConversation: webConversation, session: session, scene: scene,
                isSessionActive: isSessionActive))
    }
}
