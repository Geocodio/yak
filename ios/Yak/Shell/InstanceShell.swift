import SwiftUI
import YakKit

/// The signed-in app for one instance: the drawer and the thread screen, the session's status and the cold start.
struct InstanceShell: View {
    let model: AppModel
    /// The instance the route chose at launch; its name and account may be older than the stored copy.
    let routedInstance: Instance
    let session: InstanceSession
    let scene: SceneModel

    @State private var status: SessionStatus = .starting
    @State private var listState: ThreadListState?
    @State private var drawerModel: DrawerModel?
    /// The stored copy of the instance, refreshed once `me` has landed.
    @State private var storedInstance: Instance?
    @State private var showsSwitcher = false
    /// The web conversation of this window and instance; its web view serves every thread the window shows.
    @State private var webConversation: WebConversationModel
    /// The Observations list, when it is open in place of the thread screen.
    @State private var isObservationsOpen = false
    /// The thread the Observations list opened over; a different thread closes the list.
    @State private var observationsOverThreadID: String?
    /// Star, close and delete for this window.
    @State private var threadActions: ThreadActionsModel
    /// Opens the thread of a task link, push or row.
    @State private var taskOpener: TaskOpener
    /// The drawer moves only after the cold start has placed it; see `DrawerContainer.animates`.
    @State private var drawerAnimates = false
    @Environment(\.horizontalSizeClass) private var sizeClass
    @Environment(\.verticalSizeClass) private var verticalSizeClass
    @Environment(\.inputBlocked) private var inputBlocked
    @Environment(\.scenePhase) private var scenePhase

    init(model: AppModel, routedInstance: Instance, session: InstanceSession, scene: SceneModel) {
        self.model = model
        self.routedInstance = routedInstance
        self.session = session
        self.scene = scene
        _webConversation = State(initialValue: WebConversationModel(session: session))
        let actions = ThreadActionsModel(session: session, clock: SystemClock())
        actions.isThreadOpen = { [weak scene] id in scene?.state.threadID == id }
        actions.leaveThread = { [weak scene] _ in
            scene?.closeThread()
            scene?.setDrawerOpen(true)
        }
        actions.threadsChanged = { [weak model] removed in
            guard let model else { return }
            model.widgets?.threadsChanged()
            guard !removed.isEmpty else { return }
            await model.push?.removeDeliveredNotifications(forThreads: Set(removed), from: routedInstance)
            NotificationCenter.default.post(name: .yakThreadRemoved, object: nil)
        }
        _threadActions = State(initialValue: actions)
        let instanceURL = routedInstance.url
        _taskOpener = State(
            initialValue: TaskOpener(
                resolve: { id in
                    guard let client = await session.apiClient() else { return nil }
                    return await TaskThreadResolver.resolve(taskID: id, client: client)
                },
                openThread: { [weak scene] threadID in
                    scene?.open(thread: threadID, isCompactWidth: model.isCompactWidth)
                },
                openWebPage: { id in
                    guard
                        let page = WebLinkRule.safeURL(
                            instanceURL.appending(path: "tasks").appending(path: id).absoluteString)
                    else { return false }
                    model.browserURL = page
                    return true
                }))
    }

    private var instance: Instance { storedInstance ?? routedInstance }
    private var isCompactWidth: Bool { sizeClass != .regular }
    private var hasSeveralWindows: Bool {
        SeveralWindows.isAvailable(isCompactWidth: isCompactWidth, isCompactHeight: verticalSizeClass == .compact)
    }
    /// The list loads and polls only while the drawer is on screen and the app is in front.
    private var isListVisible: Bool { drawerBinding.wrappedValue && scenePhase == .active }

    var body: some View {
        Group {
            switch status {
            case .starting, .active: layout
            case .needsPairing:
                Color.yakBackground.task {
                    webConversation.sessionEnded()
                    await model.markRevoked(instance)
                }
            case .tokenUnreadable: problem("signedIn.tokenUnreadable")
            case .appTooOld: problem("signedIn.appTooOld")
            case .serverTooOld: problem("signedIn.serverTooOld")
            }
        }
        .task { for await next in await session.statuses() { status = next } }
        .task(id: status == .active) { await followThreadList() }
        .task(id: status == .active) { if status == .active { await threadActions.run() } }
        .webPrewarm(
            model: model, webConversation: webConversation, session: session, scene: scene,
            isSessionActive: status == .active
        )
        .task(id: status) { await refreshStoredInstance() }
        .task {
            #if DEBUG
                if let id = TestMode.taskToOpen() { model.taskToOpen = id }
            #endif
        }
        .onChange(of: scene.isSettingsOpen) { Task { await refreshStoredInstance() } }
        .task(id: drawerModel.map(ObjectIdentifier.init)) { await drawerModel?.run() }
        .onChange(of: scene.state.selection) { Task { await drawerModel?.syncFilter() } }
        .onChange(of: isListVisible) { Task { await drawerModel?.setVisible(isListVisible) } }
        .onChange(of: model.taskToOpen, initial: true) {
            if let id = model.takeTaskToOpen(for: scene) { taskOpener.open(taskID: id) }
        }
        .onChange(of: scene.state.threadID) { _, new in
            if new != observationsOverThreadID { isObservationsOpen = false }
        }
        .alert(taskProblemTitle, isPresented: taskProblemBinding) {
            Button("task.problem.ok", role: .cancel) {}
        } message: {
            Text(taskProblemMessage)
        }
        .onChange(of: status) { _, new in if new == .active { taskOpener.sessionBecameActive() } }
        .onChange(of: scene.state.threadID) { taskOpener.cancel() }
        .onDisappear { taskOpener.cancel() }
        .onChange(of: sizeClass, initial: true) { model.isCompactWidth = isCompactWidth }
        .onChange(of: model.push?.pending, initial: true) { showPending() }
        .onChange(of: inputBlocked) { showPending() }
        .sheet(isPresented: showsAccount) {
            if let push = model.push {
                SettingsScreen(app: model, push: push, active: instance, onClose: { scene.isSettingsOpen = false })
                    .linkPairingStatus(model, sheetDepth: 1)
                    .countsAsOpenSheet(model)
                    .presentationBackground(Color.yakBackground)
            }
        }
        .sheet(isPresented: $showsSwitcher) {
            InstanceSwitcher(model: model, active: instance, onClose: { showsSwitcher = false })
                .linkPairingStatus(model, sheetDepth: 1)
                .countsAsOpenSheet(model)
                .shortSheetDetents()
                .presentationBackground(Color.yakBackground)
        }
        .sheet(isPresented: isConnecting) {
            NavigationStack {
                EnterAddressView(
                    model: model, initialAddress: model.addressToConnect?.absoluteString ?? "",
                    onDone: { model.addressToConnect = nil }
                )
                .linkPairingStatus(model, sheetDepth: 1)
                .countsAsOpenSheet(model)
                .toolbar {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("address.cancel") { model.addressToConnect = nil }
                    }
                }
            }
        }
        .sheet(isPresented: isBrowsing) {
            if let url = model.browserURL { BrowserSheet(url: url).ignoresSafeArea() }
        }
        .environment(\.hasSeveralWindows, hasSeveralWindows)
        .environment(\.webConversation, webConversation)
        .focusedSceneValue(
            \.sceneCommands,
            SceneCommands.published(sceneCommands, isBlocked: inputBlocked, hasSheetOpen: hasSheetOpen)
        )
        .overlay(alignment: .bottom) { otherInstanceNotice }
    }

    /// A push tap that needs a screen of its own: the connect flow or the browser.
    private func showPending() {
        guard !inputBlocked, let pending = model.takePendingPush(for: scene) else { return }
        switch pending {
        case .pairing(let address): model.addressToConnect = address
        case .webPage(let url): model.browserURL = url
        }
    }

    // MARK: Layout

    @ViewBuilder private var layout: some View {
        if isCompactWidth {
            DrawerContainer(isOpen: drawerBinding, animates: drawerAnimates) {
                drawer
            } detail: {
                threadScreen
            }
            .onChange(of: scene.hasAppliedColdStart, initial: true) { _, hasApplied in
                if hasApplied { Task { drawerAnimates = true } }
            }
        } else {
            NavigationSplitView(columnVisibility: columnVisibility) {
                drawer
            } detail: {
                threadScreen
            }
        }
    }

    /// Before the cold start has chosen a screen the drawer is what shows.
    private var drawerBinding: Binding<Bool> {
        Binding(
            get: { scene.state.isDrawerOpen || !scene.hasAppliedColdStart },
            set: { scene.setDrawerOpen($0) })
    }

    private var columnVisibility: Binding<NavigationSplitViewVisibility> {
        Binding(
            get: { drawerBinding.wrappedValue ? .all : .detailOnly },
            set: { scene.setDrawerOpen($0 != .detailOnly) })
    }

    private var drawer: some View {
        DrawerView(
            model: model, instance: instance, scene: scene, drawer: drawerModel, actions: threadActions,
            isCompactWidth: isCompactWidth,
            openAccount: { scene.isSettingsOpen = true }, openSwitcher: { showsSwitcher = true },
            retry: { Task { await retryList() } }, setObservationsOpen: setObservationsOpen,
            openTask: { taskOpener.open(taskID: $0) }, openingTaskID: taskOpener.openingID)
    }

    /// Names the screen the detail shows, so a change of screen cross-fades.
    private var screenIdentity: AnyHashable {
        if isObservationsOpen { return AnyHashable("observations") }
        if scene.state.isNewThreadOpen { return AnyHashable("new") }
        return AnyHashable(scene.state.threadID)
    }

    private var threadScreen: some View {
        threadScreenContent.motion(Motion.crossFade, value: screenIdentity)
            .haptic(.threadSwitched, trigger: scene.state.threadID) { old, new in
                old != nil && new != nil && new != scene.followedReplacementID
            }
    }

    @ViewBuilder private var threadScreenContent: some View {
        if isObservationsOpen {
            ObservationsNavigation(
                session: session, openTask: { taskOpener.open(taskID: $0) }, openWeb: { model.browserURL = $0 },
                close: { isObservationsOpen = false })
        } else if let threadID = scene.state.threadID, !scene.state.isNewThreadOpen {
            ThreadScreen(
                session: session, scene: scene, threadID: threadID,
                initialTitle: listState?.list?.title(ofThread: threadID),
                subThreadCount: listState?.list?.row(ofThread: threadID)?.subThreadCount ?? 0,
                actions: threadActions, instanceURL: instance.url,
                needsYouCount: drawerModel?.needsYouCount ?? 0, isSessionActive: status == .active,
                isCoveredByDrawer: isCompactWidth && drawerBinding.wrappedValue, isCompactWidth: isCompactWidth,
                openWeb: { model.browserURL = $0 }, push: model.push
            )
            .id(threadID)
        } else if scene.state.isNewThreadOpen {
            NewThreadScreen(
                app: model, session: session, scene: scene, instanceURL: instance.url,
                needsYouCount: drawerModel?.needsYouCount ?? 0, isSessionActive: status == .active,
                isCompactWidth: isCompactWidth, openWeb: { model.browserURL = $0 }
            )
        } else {
            Group {
                if NoThreadView.isShown(supportsMultipleWindows: hasSeveralWindows, isCompactWidth: isCompactWidth) {
                    NoThreadView { scene.openNewThread(repo: nil, text: nil) }
                } else {
                    ThreadPlaceholder(
                        title: nil, isNewThread: false, showsThreadsButton: isCompactWidth,
                        openDrawer: { scene.setDrawerOpen(true) })
                }
            }
            .opacity(emptyDetailOpacity)
        }
    }

    /// The empty detail draws nothing beside an open drawer: the thread's strip stays dimmed only that much, and a
    /// line of gray text on it would not keep its contrast.
    private var emptyDetailOpacity: Double { isCompactWidth && drawerBinding.wrappedValue ? 0 : 1 }

    private func setObservationsOpen(_ isOpen: Bool) {
        isObservationsOpen = isOpen
        observationsOverThreadID = scene.state.threadID
        if isOpen, isCompactWidth { scene.setDrawerOpen(false) }
    }

    private var taskProblemBinding: Binding<Bool> {
        Binding(get: { taskOpener.problem != nil }, set: { if !$0 { taskOpener.problem = nil } })
    }

    private var taskProblemTitle: LocalizedStringKey {
        switch taskOpener.problem {
        case .notFound: "task.notFound.title"
        case .offline: "task.openOffline.title"
        default: "task.openFailed.title"
        }
    }

    private var taskProblemMessage: LocalizedStringKey {
        switch taskOpener.problem {
        case .notFound: "task.notFound.detail"
        case .offline: "task.openOffline.detail"
        default: "task.openFailed.detail"
        }
    }

    private func problem(_ message: LocalizedStringKey) -> some View {
        ScrollView {
            InstanceProblemView(message: message) {
                Task {
                    await session.stop()
                    await session.start()
                }
            }
            .frame(maxWidth: 560).frame(maxWidth: .infinity).padding(24)
        }
        .background(Color.yakBackground)
    }

    private func refreshStoredInstance() async {
        if let latest = await model.instances.all().first(where: { $0.id == routedInstance.id }) {
            storedInstance = latest
        }
    }

    private var showsAccount: Binding<Bool> {
        Binding(get: { scene.isSettingsOpen }, set: { scene.isSettingsOpen = $0 })
    }

    /// A sheet of this window is open: the menu commands wait for it to close.
    private var hasSheetOpen: Bool {
        scene.isSettingsOpen || scene.isThreadSheetOpen || showsSwitcher || isConnecting.wrappedValue
            || isBrowsing.wrappedValue
    }

    /// The menu commands of this window: Command N, K, backslash, 1 to 5, I and comma.
    private var sceneCommands: SceneCommands {
        SceneCommands(
            availablePanes: scene.plan?.available ?? [],
            focusPane: { scene.commandFocus($0) },
            searchThreads: { scene.requestSearchFocus() },
            newThread: { scene.openNewThread(repo: nil, text: nil) },
            toggleSidebar: { scene.setDrawerOpen(!scene.state.isDrawerOpen) },
            overview: { scene.toggleOverview() },
            settings: { scene.isSettingsOpen = true }, hasSeveralWindows: hasSeveralWindows)
    }

    /// A window that was asked for a thread of another instance says so for a few seconds.
    @ViewBuilder private var otherInstanceNotice: some View {
        if scene.showsOtherInstanceNotice {
            Text("window.otherInstance").font(.yak(.footnote, .medium)).multilineTextAlignment(.center)
                .padding(12).frame(maxWidth: 420)
                .background(Color.yakCard, in: .rect(cornerRadius: 16))
                .padding(.bottom, 72)
                .task {
                    do { try await Task.sleep(for: .seconds(6)) } catch { return }
                    scene.dismissOtherInstanceNotice()
                }
                .accessibilityIdentifier("window.otherInstance")
        }
    }

    private var isConnecting: Binding<Bool> {
        Binding(
            get: { model.addressToConnect != nil && model.presentsSheets(in: scene) },
            set: { if !$0 { model.addressToConnect = nil } })
    }

    private var isBrowsing: Binding<Bool> {
        Binding(
            get: { model.browserURL != nil && model.presentsSheets(in: scene) },
            set: { if !$0 { model.browserURL = nil } })
    }

    // MARK: Thread list and cold start

    /// Shows the list while the session is active and applies the cold start on the first unsearched `mine` list.
    private func followThreadList() async {
        guard status == .active, let feed = await session.threadList() else { return }
        let drawer = DrawerModel(
            feed: feed, outbox: await session.outbox(), scene: scene, clock: SystemClock(),
            overview: await session.overviewActions(),
            search: { [session] text throws(APIFailure) in
                guard let client = await session.apiClient() else { throw .network(.notConnectedToInternet) }
                return try await client.send(.search(text))
            })
        drawerModel = drawer
        await drawer.configure(
            serverFilters: await session.threadFilters(), viewerID: await session.me()?.user.id.value)
        await drawer.setVisible(isListVisible)
        for await state in await feed.states() {
            // The answer to `me` may come after the list started; the tabs follow it.
            await drawer.configure(
                serverFilters: await session.threadFilters(), viewerID: await session.me()?.user.id.value)
            listState = state
            await model.push?.refreshBadge()
            if state.isWholeMine, let list = state.list {
                model.widgets?.listLoaded(instance: instance, list: list)
                QuickActions.update(
                    instance: instance,
                    snapshot: WidgetSnapshot.make(from: list, instanceName: instance.name, now: Date()))
            }
            await applyColdStartIfNeeded(state)
        }
        await drawer.setVisible(false)
    }

    private func applyColdStartIfNeeded(_ state: ThreadListState) async {
        if !scene.hasAppliedColdStart {
            guard state.isWholeMine, let list = state.list else { return }
            let link = model.takeLaunchLink()
            let own: DeepLink? =
                switch link {
                case .thread(let address, _, _)?, .newThread(let address, _, _)?:
                    SceneModel.isSameAddress(address, instance.url) ? link : nil
                default: nil
                }
            scene.applyColdStart(
                ColdStart.destination(mine: list, restoredThreadID: scene.state.threadID, link: own))
            await drawerModel?.syncFilter()
            if let link { await model.handle(link: link) }
        }
    }

    /// Reloads the list, or starts the session again while it is still signing in.
    private func retryList() async {
        if let drawerModel {
            await drawerModel.retry()
        } else {
            await session.stop()
            await session.start()
        }
    }
}
