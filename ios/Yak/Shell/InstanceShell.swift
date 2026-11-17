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
    @State private var showsAccount = false
    @State private var showsSwitcher = false
    /// The tasks area, when it is open in place of the thread screen.
    @State private var tasksRoute: TasksRoute?
    /// The thread the tasks route opened over; a different thread closes the route.
    @State private var tasksOverThreadID: String?
    @Environment(\.horizontalSizeClass) private var sizeClass
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var instance: Instance { storedInstance ?? routedInstance }
    private var isCompactWidth: Bool { sizeClass != .regular }
    /// The list loads and polls only while the drawer is on screen and the app is in front.
    private var isListVisible: Bool { drawerBinding.wrappedValue && scenePhase == .active }

    var body: some View {
        Group {
            switch status {
            case .starting, .active: layout
            case .needsPairing: Color(.systemBackground).task { await model.markRevoked(instance) }
            case .tokenUnreadable: problem("signedIn.tokenUnreadable")
            case .appTooOld: problem("signedIn.appTooOld")
            case .serverTooOld: problem("signedIn.serverTooOld")
            }
        }
        .task { for await next in await session.statuses() { status = next } }
        .task(id: status == .active) { await followThreadList() }
        .task(id: status) { await refreshStoredInstance() }
        .task {
            #if DEBUG
                if let id = TestMode.taskToOpen() { model.taskToOpen = id }
            #endif
        }
        .onChange(of: showsAccount) { Task { await refreshStoredInstance() } }
        .task(id: drawerModel.map(ObjectIdentifier.init)) { await drawerModel?.run() }
        .onChange(of: scene.state.filter) { Task { await drawerModel?.syncFilter() } }
        .onChange(of: isListVisible) { Task { await drawerModel?.setVisible(isListVisible) } }
        .onChange(of: model.taskToOpen, initial: true) {
            guard let id = model.taskToOpen else { return }
            model.taskToOpen = nil
            openTasks(.task(id))
        }
        .onChange(of: scene.state.threadID) { _, new in if new != tasksOverThreadID { tasksRoute = nil } }
        .onChange(of: sizeClass, initial: true) { model.isCompactWidth = isCompactWidth }
        .sheet(isPresented: $showsAccount) {
            SignedInView(model: model, instance: instance, onClose: { showsAccount = false })
                .linkPairingStatus(model, sheetDepth: 1)
                .countsAsOpenSheet(model)
        }
        .sheet(isPresented: $showsSwitcher) {
            InstanceSwitcher(model: model, active: instance, onClose: { showsSwitcher = false })
                .linkPairingStatus(model, sheetDepth: 1)
                .countsAsOpenSheet(model)
                .presentationDetents(dynamicTypeSize.isAccessibilitySize ? [.large] : [.medium, .large])
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
        .background { shortcuts }
    }

    // MARK: Layout

    @ViewBuilder private var layout: some View {
        if isCompactWidth {
            DrawerContainer(isOpen: drawerBinding) {
                drawer
            } detail: {
                threadScreen
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
            model: model, instance: instance, scene: scene, drawer: drawerModel, isCompactWidth: isCompactWidth,
            openAccount: { showsAccount = true }, openSwitcher: { showsSwitcher = true },
            retry: { Task { await retryList() } }, openTasksRoute: openTasks)
    }

    @ViewBuilder private var threadScreen: some View {
        if let route = tasksRoute {
            TasksNavigation(
                route: route, session: session, scene: scene, instanceURL: instance.url,
                openWeb: { model.browserURL = $0 }, close: { tasksRoute = nil }
            )
            .id(route)
        } else if let threadID = scene.state.threadID, !scene.state.isNewThreadOpen {
            ThreadScreen(
                session: session, scene: scene, threadID: threadID,
                initialTitle: listState?.list?.title(ofThread: threadID), instanceURL: instance.url,
                needsYouCount: drawerModel?.needsYouCount ?? 0, isSessionActive: status == .active,
                isCoveredByDrawer: isCompactWidth && drawerBinding.wrappedValue, isCompactWidth: isCompactWidth,
                openWeb: { model.browserURL = $0 }
            )
            .id(threadID)
        } else if scene.state.isNewThreadOpen {
            NewThreadScreen(
                app: model, session: session, scene: scene, instanceURL: instance.url,
                needsYouCount: drawerModel?.needsYouCount ?? 0, isSessionActive: status == .active,
                isCompactWidth: isCompactWidth, openWeb: { model.browserURL = $0 }
            )
        } else {
            ThreadPlaceholder(
                title: nil, isNewThread: false, showsThreadsButton: isCompactWidth,
                openDrawer: { scene.setDrawerOpen(true) })
        }
    }

    private func openTasks(_ route: TasksRoute?) {
        tasksRoute = route
        tasksOverThreadID = scene.state.threadID
        if route != nil, isCompactWidth { scene.setDrawerOpen(false) }
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
        .background(Color(.systemBackground))
    }

    private func refreshStoredInstance() async {
        if let latest = await model.instances.all().first(where: { $0.id == routedInstance.id }) {
            storedInstance = latest
        }
    }

    /// Command N, Command K (the drawer's search field) and Command backslash.
    private var shortcuts: some View {
        VStack {
            Button("shell.newThread") { scene.openNewThread(repo: nil, text: nil) }.yakShortcut(.newThread)
            Button("shell.threads") { scene.requestSearchFocus() }.yakShortcut(.searchThreads)
            Button("shell.threads") { scene.setDrawerOpen(!scene.state.isDrawerOpen) }.yakShortcut(.toggleSidebar)
        }
        .frame(width: 0, height: 0).opacity(0).accessibilityHidden(true)
    }

    private var isConnecting: Binding<Bool> {
        Binding(get: { model.addressToConnect != nil }, set: { if !$0 { model.addressToConnect = nil } })
    }

    private var isBrowsing: Binding<Bool> {
        Binding(get: { model.browserURL != nil }, set: { if !$0 { model.browserURL = nil } })
    }

    // MARK: Thread list and cold start

    /// Shows the list while the session is active and applies the cold start on the first unsearched `mine` list.
    private func followThreadList() async {
        guard status == .active, let feed = await session.threadList() else { return }
        let drawer = DrawerModel(feed: feed, outbox: await session.outbox(), scene: scene, clock: SystemClock())
        drawerModel = drawer
        await drawer.setVisible(isListVisible)
        for await state in await feed.states() {
            listState = state
            await applyColdStartIfNeeded(state)
        }
        await drawer.setVisible(false)
    }

    private func applyColdStartIfNeeded(_ state: ThreadListState) async {
        if !scene.hasAppliedColdStart {
            guard state.filter == .mine, state.search == nil, let list = state.list else { return }
            let link = model.takeLaunchLink()
            let own: DeepLink? =
                switch link {
                case .thread(let address, _)?, .newThread(let address, _, _)?:
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

extension ThreadList {
    /// The title of a thread row anywhere in the list, sub-threads included.
    func title(ofThread id: String) -> String? {
        func find(_ rows: [ThreadRow]) -> String? {
            for row in rows {
                if row.id == id { return row.title }
                if let title = find(row.children) { return title }
            }
            return nil
        }
        let needy = needsYou.compactMap { item -> ThreadRow? in
            if case .thread(let row) = item { row } else { nil }
        }
        return find(needy) ?? find(starred) ?? find(threads)
    }
}
