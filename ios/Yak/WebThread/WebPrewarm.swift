import SwiftUI
import YakKit

/// Prepares the web conversation of the thread that is likely to open next while the drawer is on screen or the app
/// comes to the front, so a tap on a row finds the page loading or ready. The rules are `WebPrewarmPolicy`'s.
struct WebPrewarm: ViewModifier {
    let model: AppModel
    let webConversation: WebConversationModel
    let session: InstanceSession
    let scene: SceneModel
    let drawer: DrawerModel?
    let isSessionActive: Bool
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.inputBlocked) private var inputBlocked
    @Environment(\.previewDefaults) private var defaults
    @Environment(SystemFeatures.self) private var system

    /// Everything the rules read, so the task runs again when one of them changes.
    private struct Key: Hashable {
        let isActive: Bool
        let isLocked: Bool
        let isSessionActive: Bool
        let isOnline: Bool
        let isLowDataMode: Bool
        let threadID: String?
        let firstListedThreadID: String?
        let isDrawerOpen: Bool
    }

    private var key: Key {
        Key(
            isActive: scenePhase == .active,
            isLocked: inputBlocked || system.appLock.coversContent(sceneIsActive: scenePhase == .active),
            isSessionActive: isSessionActive, isOnline: model.network.isOnline,
            isLowDataMode: model.network.isConstrained, threadID: scene.state.threadID,
            firstListedThreadID: drawer?.firstThreadID, isDrawerOpen: scene.state.isDrawerOpen)
    }

    private var isTurnedOff: Bool {
        #if DEBUG
            TestMode.value("yakTestPrewarm") == "off"
        #else
            false
        #endif
    }

    func body(content: Content) -> some View {
        content.task(id: key) { await prewarm(key) }
    }

    private func prewarm(_ key: Key) async {
        guard !isTurnedOff, key.isActive, !key.isLocked, key.isSessionActive, key.isOnline, !key.isLowDataMode,
            !webConversation.hasPreparedPage, let me = await session.me(), me.supportsEmbeddedThread
        else { return }
        webConversation.configure(supportsEmbeddedThread: true)
        // The thread the window shows or will show, else the last one opened, else the first one listed.
        let lastOpened = await session.instanceCache()?.lastOpenedThreadID()
        let candidate = key.threadID ?? lastOpened ?? key.firstListedThreadID
        let policy = WebPrewarmPolicy(
            isActive: key.isActive, isLocked: key.isLocked, isSessionActive: key.isSessionActive,
            isOnline: key.isOnline, isLowDataMode: key.isLowDataMode, serverOffersPage: true,
            hasPreparedPage: webConversation.hasPreparedPage, candidateThreadID: candidate)
        guard let id = policy.threadToPrepare, !Task.isCancelled else { return }
        webConversation.makePage = { [defaults, session] model in
            WebConversationPageView(
                model: model,
                dataStore: PreviewWebData.store(for: session.instance.id, purpose: .embed, defaults: defaults),
                version: session.appVersion)
        }
        webConversation.prewarm(thread: id)
    }
}

extension View {
    func webPrewarm(
        model: AppModel, webConversation: WebConversationModel, session: InstanceSession, scene: SceneModel,
        drawer: DrawerModel?, isSessionActive: Bool
    ) -> some View {
        modifier(
            WebPrewarm(
                model: model, webConversation: webConversation, session: session, scene: scene, drawer: drawer,
                isSessionActive: isSessionActive))
    }
}
