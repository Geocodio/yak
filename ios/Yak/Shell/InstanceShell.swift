import SwiftUI
import YakKit

/// The signed-in app for one instance: the drawer and the thread screen, the session's status and the cold start.
struct InstanceShell: View {
    let model: AppModel
    let instance: Instance
    let session: InstanceSession
    let scene: SceneModel

    @State private var status: SessionStatus = .starting
    @State private var listState: ThreadListState?
    @State private var feed: ThreadListFeed?
    @State private var showsAccount = false
    @Environment(\.horizontalSizeClass) private var sizeClass
    @Environment(\.scenePhase) private var scenePhase

    private var isCompactWidth: Bool { sizeClass != .regular }

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
        .onChange(of: scene.state.filter) { Task { await feed?.setFilter(scene.state.filter, search: nil) } }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active { Task { await feed?.setVisible(true) } }
        }
        .onChange(of: sizeClass, initial: true) { model.isCompactWidth = isCompactWidth }
        .sheet(isPresented: $showsAccount) {
            SignedInView(model: model, instance: instance, onClose: { showsAccount = false })
                .countsAsOpenSheet(model)
        }
        .sheet(isPresented: isConnecting) {
            NavigationStack {
                EnterAddressView(
                    model: model, initialAddress: model.addressToConnect?.absoluteString ?? "",
                    onDone: { model.addressToConnect = nil }
                )
                .linkPairingStatus(model, inSheet: true)
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
        DrawerPlaceholder(
            model: model, instance: instance, scene: scene, state: listState, isCompactWidth: isCompactWidth,
            retry: { Task { await feed?.refresh() } }, openAccount: { showsAccount = true })
    }

    private var threadScreen: some View {
        ThreadPlaceholder(
            title: scene.state.threadID.map {
                listState?.list?.title(ofThread: $0) ?? String(localized: "shell.thread")
            },
            isNewThread: scene.state.isNewThreadOpen, showsThreadsButton: isCompactWidth,
            openDrawer: { scene.setDrawerOpen(true) })
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

    /// Command N, Command K and Command backslash. Task 10 adds the drawer's search field that Command K focuses.
    private var shortcuts: some View {
        VStack {
            Button("shell.newThread") { scene.openNewThread(repo: nil, text: nil) }.keyboardShortcut("n")
            Button("shell.threads") { scene.setDrawerOpen(true) }.keyboardShortcut("k")
            Button("shell.threads") { scene.setDrawerOpen(!scene.state.isDrawerOpen) }.keyboardShortcut("\\")
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
        self.feed = feed
        await feed.setVisible(true)
        for await state in await feed.states() {
            listState = state
            await applyColdStartIfNeeded(state, feed: feed)
        }
        await feed.setVisible(false)
    }

    private func applyColdStartIfNeeded(_ state: ThreadListState, feed: ThreadListFeed) async {
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
            if let link { await model.handle(link: link) }
        }
        if state.filter != scene.state.filter, state.search == nil {
            await feed.setFilter(scene.state.filter, search: nil)
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
