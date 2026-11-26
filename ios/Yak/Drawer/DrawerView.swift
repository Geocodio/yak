import SwiftUI
import YakKit

/// The threads drawer: the instance and account at the top, the list in the middle, and the search, the filter and
/// the new-thread button at the bottom, where a thumb reaches them.
struct DrawerView: View {
    let model: AppModel
    let instance: Instance
    let scene: SceneModel
    /// Nil until the session is active; the drawer shows its loading state meanwhile.
    let drawer: DrawerModel?
    /// Star, close and delete.
    let actions: ThreadActionsModel
    let isCompactWidth: Bool
    let openAccount: () -> Void
    let openSwitcher: () -> Void
    /// Reloads the list, or starts the session again while it is still signing in.
    let retry: () -> Void
    /// Shows or hides the Observations list in place of the thread screen.
    let setObservationsOpen: (Bool) -> Void
    /// Opens a task that has no thread; the shell finds where it lives.
    let openTask: (String) -> Void
    /// The task a tap is looking up, shown as a spinner on its row.
    let openingTaskID: String?

    @FocusState private var isSearchFocused: Bool
    @State private var isFilterOpen = false
    @State private var dropTarget: DrawerRow?
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    @Environment(\.verticalSizeClass) private var verticalSizeClass

    var body: some View {
        VStack(spacing: 0) {
            if !(isSearchFocused && (verticalSizeClass == .compact || dynamicTypeSize.isAccessibilitySize)),
                !headerScrollsWithList
            {
                header
            }
            if let name = scene.justPairedUserName { banner(name) }
            if !(isSearchFocused && (verticalSizeClass == .compact || dynamicTypeSize.isAccessibilitySize)) {
                tiles
                tabRow
            }
            content.frame(maxHeight: .infinity, alignment: .top)
        }
        // Whatever sits above the bottom bar is clipped to the room left, so the bar is always fully on screen.
        .frame(maxHeight: .infinity, alignment: .top)
        .clipped()
        .safeAreaInset(edge: .bottom, spacing: 0) {
            VStack(spacing: 0) {
                actionLine(drawer)
                toast
                bottomBar
            }
        }
        .motion(Motion.panel, value: drawer?.actionLine)
        .motion(Motion.panel, value: actions.notice?.id)
        .threadActionDialogs(actions, surface: .row)
        .sensoryFeedback(.error, trigger: drawer?.refusalCount ?? 0)
        .confirmationDialog(
            Text("overview.drop.title \(dropTarget?.title ?? "")"), isPresented: dropBinding, titleVisibility: .visible
        ) {
            Button("overview.child.drop", role: .destructive) {
                if let target = dropTarget, let drawer { Task { await drawer.perform(.drop, on: target) } }
            }
            Button("overview.cancel", role: .cancel) {}
        } message: {
            Text("overview.drop.message")
        }
        .sheet(isPresented: $isFilterOpen) {
            if let drawer {
                DrawerFilterSheet(drawer: drawer) { isFilterOpen = false }.shortSheetDetents()
            }
        }
        .background(Color.yakSidebar)
        .overlay(alignment: .trailing) { Rectangle().fill(Color.yakLine).frame(width: Design.Space.line) }
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("drawer")
        .onChange(of: scene.searchFocusRequest) { isSearchFocused = true }
    }

    // MARK: Header

    /// At accessibility sizes the header scrolls with the list or the message, so they get the height.
    private var headerScrollsWithList: Bool {
        guard dynamicTypeSize.isAccessibilitySize, !isSearchFocused else { return false }
        if drawer?.state?.list != nil, drawer?.content != nil { return true }
        if case .failed? = drawer?.state?.load { return true }
        return false
    }

    /// The name, then the account. When the name and the account do not fit side by side at an accessibility size,
    /// the account moves below the name, which then gets the full width.
    @ViewBuilder private var header: some View {
        let row = HStack(spacing: 8) {
            switcherButton
            Spacer(minLength: 0)
            accountButton
        }
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                ViewThatFits(in: .horizontal) {
                    row
                    VStack(alignment: .leading, spacing: 0) {
                        switcherButton
                        accountButton
                    }
                }
            } else {
                row
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 4)
        .cappedAtAccessibility2(when: dynamicTypeSize.isAccessibilitySize)
    }

    private var switcherButton: some View {
        Button(action: openSwitcher) {
            HStack(spacing: 6) {
                instanceName
                Image(systemName: "chevron.up.chevron.down").font(.yak(.footnote, .semibold))
                    .foregroundStyle(Color.yakMuted)
            }
            .frame(minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.pressable)
        .accessibilityLabel("drawer.switcher \(instance.name)")
        .layoutPriority(1)
        .accessibilityIdentifier("drawer.switcher")
    }

    private var accountButton: some View {
        Button(action: openAccount) {
            Text(initials).font(.yak(.subheadline, .semibold)).foregroundStyle(.primary)
                .lineLimit(1).fixedSize().dynamicTypeSize(...DynamicTypeSize.accessibility1)
                .padding(8).frame(minWidth: 36, minHeight: 36)
                .background(Color.primary.opacity(0.1), in: .capsule)
                .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.pressable)
        .accessibilityLabel("drawer.accountLabel \(instance.userName)")
        .accessibilityIdentifier("drawer.account")
    }

    private var initials: String {
        let letters = instance.userName.split(separator: " ").prefix(2).compactMap(\.first)
        return letters.isEmpty ? "?" : String(letters).uppercased()
    }

    /// Scaling the name down is skipped at accessibility sizes, where it wraps between words at the full width.
    @ViewBuilder private var instanceName: some View {
        let name = Text(instance.name).multilineTextAlignment(.leading)
        if dynamicTypeSize.isAccessibilitySize {
            name.font(.yak(.headline, .semibold)).fixedSize(horizontal: false, vertical: true)
        } else {
            name.font(.yak(.headline, .semibold)).lineLimit(2).minimumScaleFactor(0.6).fixedSize(
                horizontal: false, vertical: true)
        }
    }

    private func banner(_ name: String) -> some View {
        HStack {
            Text("signedIn.signedInAs \(name)").font(.yak(.subheadline)).fixedSize(horizontal: false, vertical: true)
            Spacer()
            Button {
                scene.justPairedUserName = nil
            } label: {
                Image(systemName: "xmark").frame(minWidth: 44, minHeight: 44)
            }
            .foregroundStyle(.primary)
            .accessibilityLabel("drawer.dismissBanner")
        }
        .padding(.leading, 20)
        .background(Color.primary.opacity(0.06))
    }

    // MARK: Tiles

    /// The Observations tile above the list.
    private var tiles: some View {
        Button {
            setObservationsOpen(true)
        } label: {
            // At accessibility sizes the icon sits above the name, which then keeps its whole width.
            let layout =
                dynamicTypeSize.isAccessibilitySize
                ? AnyLayout(VStackLayout(spacing: 4)) : AnyLayout(HStackLayout(spacing: 8))
            layout {
                Image(systemName: "eye").font(.system(.body, weight: .light)).accessibilityHidden(true)
                Text("drawer.tile.observations").font(.yak(.subheadline, .medium))
                    .fixedSize(horizontal: false, vertical: true)
            }
            .padding(.vertical, dynamicTypeSize.isAccessibilitySize ? 8 : 0)
            .foregroundStyle(.primary)
            .frame(maxWidth: .infinity, minHeight: 44)
            .yakCardSurface(in: .rect(cornerRadius: Design.Radius.card))
            .contentShape(.rect(cornerRadius: Design.Radius.card))
        }
        .buttonStyle(.pressable)
        .accessibilityIdentifier("drawer.tile.observations")
        .padding(.horizontal, 16).padding(.vertical, 6)
    }

    // MARK: Tabs

    private var selection: ThreadListSelection { drawer?.selection ?? scene.state.selection }

    /// The tabs stay in place while a list loads, so the row never jumps.
    private var tabRow: some View {
        VStack(spacing: 0) {
            DrawerTabsView(
                tabs: drawer?.tabs.tabs ?? ThreadTabs(serverFilters: nil).tabs, selected: selection.filter,
                reviewCount: drawer?.reviewCount ?? 0, filterCount: selection.activeCount,
                pick: { tab in
                    if let drawer {
                        Task { await drawer.setFilter(tab) }
                    } else {
                        scene.setFilter(tab)
                    }
                },
                openFilter: { isFilterOpen = true }
            )
            if selection.activeCount > 0, let drawer {
                DrawerFilterChips(
                    chips: chips(drawer),
                    remove: { key in
                        Task {
                            if selection.sources.contains(key) {
                                await drawer.toggleSource(key)
                            } else {
                                await drawer.toggleStarter(key)
                            }
                        }
                    },
                    clear: { Task { await drawer.clearFilters() } })
            }
        }
        .padding(.horizontal, Design.Space.groupInset)
    }

    private func chips(_ drawer: DrawerModel) -> [(key: String, label: String)] {
        let sources = drawer.sourceChoices().filter(\.isPicked).map { (key: $0.key, label: $0.label) }
        let starters = drawer.starterChoices().filter(\.isPicked).map { choice in
            let label =
                switch choice.role {
                case .viewer: String(localized: "drawer.filterSheet.you")
                case .yak: String(localized: "drawer.filterSheet.yak")
                case .person: choice.label
                }
            return (key: choice.key, label: label)
        }
        return sources + starters
    }

    // MARK: Content

    @ViewBuilder private var content: some View {
        if let drawer, let state = drawer.state, state.list != nil, let shown = drawer.content {
            VStack(spacing: 0) {
                if state.isOffline {
                    notice(offlineSentence(asOf: state.listAsOf), systemImage: "wifi.slash")
                }
                switch shown {
                case .sections(let sections): list(sections, drawer: drawer)
                case .empty(let othersHaveThreads): empty(othersHaveThreads: othersHaveThreads, drawer: drawer)
                case .noResults(let search, let canWiden): noResults(search, canWiden: canWiden, drawer: drawer)
                }
            }
        } else if case .failed(let problem)? = drawer?.state?.load {
            failure(problem)
        } else {
            DrawerLoading(isSlow: drawer?.state?.load == .slow, retry: retry)
        }
    }

    /// "Offline. Showing the list from 08:16", with the day when the list is from another day; a list saved before
    /// the time was kept says "a saved copy".
    private func offlineSentence(asOf: Date?) -> Text {
        guard let asOf else { return Text("drawer.offlineSaved") }
        let isToday = Calendar.current.isDateInToday(asOf)
        let when =
            isToday
            ? asOf.formatted(date: .omitted, time: .shortened)
            : asOf.formatted(.dateTime.month(.abbreviated).day().hour().minute())
        return Text("drawer.offlineSavedAt \(when)")
    }

    private func list(_ sections: [DrawerSection], drawer: DrawerModel) -> some View {
        // The ages are worked out from the clock, which ticks every minute.
        return TimelineView(.everyMinute) { context in
            List {
                if headerScrollsWithList {
                    Section {
                    } header: {
                        header.textCase(nil).foregroundStyle(Color.primary).listRowInsets(.init())
                    }
                }
                ForEach(sections) { section in
                    Section {
                        ForEach(section.rows) { row in
                            rowView(row, now: context.date, drawer: drawer)
                                .listRowInsets(
                                    .init(
                                        top: 0, leading: Design.Space.compact, bottom: 0, trailing: Design.Space.compact
                                    )
                                )
                                .listRowSeparator(.hidden)
                                .listRowBackground(Color.clear)
                                .transition(.opacity)
                        }
                    } header: {
                        sectionHeading(section)
                            .textCase(nil)
                            .padding(.leading, dynamicTypeSize.isAccessibilitySize ? 12 : 20)
                            .padding(.top, 6)
                            .listRowInsets(.init())
                            .accessibilityAddTraits(.isHeader)
                    }
                    .listSectionSeparator(.hidden)
                }
            }
            .environment(\.defaultMinListHeaderHeight, 0)
            .listStyle(.plain)
            .contentMargins(.bottom, Design.Space.large, for: .scrollContent)
            .scrollContentBackground(.hidden)
            .scrollDismissesKeyboard(.interactively)
            .motion(Motion.content, value: drawer.content)
            .accessibilityIdentifier("drawer.list")
        }
    }

    /// A sub-thread row offers a long press menu and swipe actions.
    @ViewBuilder private func rowView(_ row: DrawerRow, now: Date, drawer: DrawerModel) -> some View {
        let plain = DrawerRowView(
            row: row, now: now, open: { open(row) }, toggle: { drawer.toggle(row.id) },
            isRunning: drawer.runningChildID.map { "thread:\($0)" } == row.id
                || row.id == openingTaskID.map { "task:\($0)" } || threadID(of: row).map(actions.isWorking) == true,
            busy: threadID(of: row).flatMap {
                actions.isDeleting($0) ? .deleting : (actions.isClosing($0) ? .closing : nil)
            },
            isSelected: !scene.state.isNewThreadOpen && scene.state.threadID.map { "thread:\($0)" } == row.id,
            openPullRequest: { openPullRequest($0, drawer: drawer) })
        // Every row carries the same modifiers, so the list holds one kind of row; only a sub-thread fills them.
        let isSubThread: Bool = if row.depth > 0, case .thread = row.target { true } else { false }
        let menu = drawer.childMenu(row)
        let rowMenu = ThreadActionMenu.make(
            .row(row, isOffline: drawer.isOffline, isBusy: threadID(of: row).map(actions.isBusy) == true))
        plain
            .contextMenu {
                if threadID(of: row) != nil {
                    if isSubThread {
                        childMenuItems(row, menu: menu, drawer: drawer)
                        ThreadActionItems(
                            menu: ThreadActionMenu(groups: [rowMenu.items.filter { $0.kind == .copyLink }]),
                            identifierPrefix: "drawer.menu", perform: { perform($0, on: row, drawer: drawer) })
                    } else {
                        ThreadActionItems(
                            menu: rowMenu, identifierPrefix: "drawer.menu",
                            perform: { perform($0, on: row, drawer: drawer) },
                            custom: { kind in
                                guard kind == .openInNewWindow, case .thread(let id) = row.target else { return nil }
                                return AnyView(OpenInNewWindowButton(threadID: id, instanceID: instance.id))
                            })
                        ForEach(Array((row.reviewLine?.actionableLinks ?? []).enumerated()), id: \.offset) { _, link in
                            Button {
                                openPullRequest(link, drawer: drawer)
                            } label: {
                                Text("drawer.review.open \(link.text)")
                            }
                        }
                    }
                }
            } preview: {
                DrawerRowView(row: row, now: now, open: {}, toggle: {}, isRunning: false, showsEverything: true)
                    .frame(width: Design.Size.previewWidth)
                    .fixedSize(horizontal: false, vertical: true)
                    .onAppear { if isSubThread { drawer.menuOpening(row) } }
            }
            .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                if isSubThread, case .ready(let actions) = menu, !drawer.isOffline {
                    if actions.contains(.drop) {
                        Button(role: .destructive) {
                            dropTarget = row
                        } label: {
                            Text("overview.child.drop")
                        }
                    }
                    if actions.contains(.takeOver) {
                        Button {
                            Task { await drawer.perform(.takeOver, on: row) }
                        } label: {
                            Text("overview.child.takeOver")
                        }
                        .tint(Color(.systemGray))
                    }
                }
            }
            .swipeActions(edge: .leading, allowsFullSwipe: false) {
                if isSubThread {
                    Button {
                        open(row)
                    } label: {
                        Text("overview.child.open")
                    }.tint(Color(.systemGray2))
                }
            }
    }

    private func threadID(of row: DrawerRow) -> String? {
        if case .thread(let id) = row.target { id } else { nil }
    }

    /// One entry of a row's long-press menu.
    private func perform(_ item: ThreadActionItem, on row: DrawerRow, drawer: DrawerModel) {
        guard let id = threadID(of: row) else { return }
        switch item.kind {
        case .open: open(row)
        case .star: actions.setStar(id, to: true, from: .row)
        case .unstar: actions.setStar(id, to: false, from: .row)
        case .copyLink:
            UIPasteboard.general.url = instance.url.appending(path: "threads").appending(path: id)
            drawer.say(String(localized: "thread.linkCopied"))
        case .openSource(_, let url): model.browserURL = url
        case .close, .delete:
            actions.ask(
                item.kind == .close ? .close : .delete, from: .row, threadID: id,
                subThreadCount: drawer.state?.list?.row(ofThread: id)?.subThreadCount ?? 0)
        default: break
        }
    }

    @ViewBuilder private func childMenuItems(_ row: DrawerRow, menu: DrawerChildMenu, drawer: DrawerModel)
        -> some View
    {
        Button {
            open(row)
        } label: {
            Text("overview.child.open")
        }
        if case .thread(let id) = row.target, case .loading = menu {
            OpenInNewWindowButton(threadID: id, instanceID: instance.id)
        }
        switch menu {
        case .loading:
            Button {
            } label: {
                Text("drawer.child.checking")
            }.disabled(true)
        case .ready(let actions):
            if actions.contains(.takeOver) {
                Button {
                    Task { await drawer.perform(.takeOver, on: row) }
                } label: {
                    Text("overview.child.takeOver")
                    Text("overview.child.takeOver.subtitle")
                }
                .disabled(drawer.isOffline)
            }
            if actions.contains(.split) {
                Button {
                    Task { await drawer.perform(.split, on: row) }
                } label: {
                    Text("overview.child.split")
                }
                .disabled(drawer.isOffline)
            }
            if case .thread(let id) = row.target {
                OpenInNewWindowButton(threadID: id, instanceID: instance.id)
            }
            if actions.contains(.drop) {
                Button(role: .destructive) {
                    dropTarget = row
                } label: {
                    Text("overview.child.drop")
                }
                .disabled(drawer.isOffline)
            }
        }
    }

    private var dropBinding: Binding<Bool> {
        Binding(get: { dropTarget != nil }, set: { if !$0 { dropTarget = nil } })
    }

    /// "Thread deleted." or why an action on a row did not happen, above the bottom controls.
    @ViewBuilder private var toast: some View {
        if let notice = actions.notice, notice.surface == .row {
            ThreadActionToast(notice: notice).transition(.opacity)
        }
    }

    /// The server's sentence about a refused action, above the bottom controls.
    @ViewBuilder private func actionLine(_ drawer: DrawerModel?) -> some View {
        if let line = drawer?.actionLine {
            Text(line).font(.yak(.footnote, .medium)).foregroundStyle(.primary)
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 20).padding(.vertical, 10)
                .background(Color.primary.opacity(0.06))
                .accessibilityIdentifier("drawer.actionLine")
                .transition(.opacity)
        }
    }

    /// A section's name in the quiet color, with a count in a pill for the threads that need the person.
    @ViewBuilder private func sectionHeading(_ section: DrawerSection) -> some View {
        let heading = HStack(spacing: Design.Space.compact) {
            title(of: section.kind).font(.yakGroupHeading).foregroundStyle(Color.yakMuted)
            if section.kind == .needsYou || isReview(section.kind) {
                Text(section.count, format: .number).font(.yak(.caption, .semibold)).foregroundStyle(
                    Color.yakMuted
                )
                .padding(.horizontal, Design.Space.small).padding(.vertical, Design.Space.hairline)
                .background(Color.yakBubble, in: .capsule)
            }
            if section.isCollapsible {
                Image(systemName: "chevron.right").font(.yak(.caption, .semibold)).foregroundStyle(Color.yakMuted)
                    .rotationEffect(.degrees(section.isCollapsed ? 0 : 90)).accessibilityHidden(true)
            }
        }
        if section.isCollapsible, case .review(let key, _) = section.kind {
            Button {
                drawer?.toggleGroup(key)
            } label: {
                heading.frame(minHeight: Design.Size.control, alignment: .leading).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityElement(children: .combine)
            .accessibilityValue(section.isCollapsed ? "drawer.group.collapsed" : "drawer.group.expanded")
            .accessibilityIdentifier("drawer.group.\(key)")
        } else {
            heading.accessibilityElement(children: .combine)
        }
    }

    private func isReview(_ kind: DrawerSection.Kind) -> Bool {
        if case .review = kind { true } else { false }
    }

    private func title(of kind: DrawerSection.Kind) -> Text {
        switch kind {
        case .notSentYet: Text("drawer.notSentYet")
        case .needsYou: Text("drawer.needsYou")
        case .starred: Text("drawer.starred")
        case .today: Text("drawer.today")
        case .thisWeek: Text("drawer.thisWeek")
        case .older: Text("drawer.older")
        case .review(_, let label): Text(verbatim: label)
        }
    }

    /// A pull request number opens its page in the in-app browser. Offline, the row says so instead.
    private func openPullRequest(_ link: ReviewLine.Link, drawer: DrawerModel) {
        guard !drawer.isOffline else {
            drawer.say(String(localized: "drawer.review.offline"))
            return
        }
        guard let url = link.url else { return }
        model.browserURL = url
    }

    private func open(_ row: DrawerRow) {
        switch row.target {
        case .thread(let id):
            isSearchFocused = false
            setObservationsOpen(false)
            scene.open(thread: id, isCompactWidth: isCompactWidth)
        case .task(let id): openTask(id)
        case .unsentThread(let id):
            guard let item = drawer?.unsentItem(id), case .newThread(let draft) = item.target else {
                scene.openNewThread(repo: nil, text: nil)
                return
            }
            scene.openNewThread(repo: draft.repo, text: item.text, item: item.id)
        }
    }

    // MARK: States

    private func notice(_ text: Text, systemImage: String) -> some View {
        Label {
            text.font(.yak(.footnote, .medium)).fixedSize(horizontal: false, vertical: true)
        } icon: {
            Image(systemName: systemImage).accessibilityHidden(true)
        }
        .foregroundStyle(Color.yakMuted)
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 20).padding(.vertical, 8)
        .background(Color.primary.opacity(0.05))
        .accessibilityElement(children: .combine)
        .accessibilityIdentifier("drawer.offlineNotice")
    }

    private func empty(othersHaveThreads: Bool, drawer: DrawerModel) -> some View {
        // The web asks for the GitHub link before it says that nothing matches.
        if drawer.filter == .review, drawer.state?.list?.needsGitHubLink == true {
            return AnyView(
                stateMessage(
                    title: Text("drawer.review.link.title"), detail: Text("drawer.review.link.detail"),
                    systemImage: "link"
                ) {
                    if let path = drawer.state?.list?.gitHubLinkPath, path.hasPrefix("/") {
                        Button {
                            model.browserURL = ReviewLink.url(path: path, instance: instance.url)
                        } label: {
                            Text("drawer.review.link.button")
                        }
                        .buttonStyle(SecondaryButtonStyle())
                        .accessibilityIdentifier("drawer.review.link")
                    }
                })
        }
        if drawer.selection.activeCount > 0 {
            return AnyView(
                stateMessage(
                    title: Text("drawer.empty.noMatch"), detail: nil, systemImage: "line.3.horizontal.decrease"
                ) {
                    Button {
                        Task { await drawer.clearFilters() }
                    } label: {
                        Text("drawer.filterSheet.clear")
                    }
                    .buttonStyle(SecondaryButtonStyle())
                    .accessibilityIdentifier("drawer.empty.clearFilters")
                })
        }
        if drawer.filter != .mine {
            return AnyView(
                stateMessage(
                    title: Text("drawer.empty.here"),
                    detail: drawer.filter == .review ? Text("drawer.empty.review") : nil,
                    systemImage: "bubble.left.and.text.bubble.right"
                ) {})
        }
        return AnyView(mineEmpty(othersHaveThreads: othersHaveThreads, drawer: drawer))
    }

    private func mineEmpty(othersHaveThreads: Bool, drawer: DrawerModel) -> some View {
        stateMessage(
            title: Text("drawer.empty.title"), detail: Text("drawer.empty.detail"),
            systemImage: "bubble.left.and.text.bubble.right"
        ) {
            Button {
                scene.openNewThread(repo: nil, text: nil)
            } label: {
                Text("shell.startThread")
            }
            .buttonStyle(PrimaryButtonStyle())
            .accessibilityIdentifier("drawer.empty.start")
            if othersHaveThreads {
                Button {
                    Task { await drawer.setFilter(.everyone) }
                } label: {
                    Text("drawer.showEveryone")
                }
                .buttonStyle(SecondaryButtonStyle())
                .accessibilityIdentifier("drawer.empty.everyone")
            }
        }
    }

    private func noResults(_ search: String, canWiden: Bool, drawer: DrawerModel) -> some View {
        let scope: LocalizedStringKey =
            switch drawer.filter {
            case .mine: "drawer.noResults.mine"
            case .yak: "drawer.noResults.yak"
            case .everyone: "drawer.noResults.everyone"
            case .closed: "drawer.noResults.closed"
            case .review: "drawer.noResults.review"
            }
        return stateMessage(
            title: Text("drawer.noResults.title \(search)"), detail: Text(scope), systemImage: "magnifyingglass"
        ) {
            if canWiden {
                Button {
                    Task { await drawer.widenSearch() }
                } label: {
                    Text("drawer.noResults.widen")
                }
                .buttonStyle(SecondaryButtonStyle())
                .accessibilityIdentifier("drawer.noResults.widen")
            }
        }
    }

    private func failure(_ problem: ThreadProblem) -> some View {
        let message: Text =
            switch problem {
            case .offline: Text("drawer.offline")
            case .server(_, let text) where !text.isEmpty: Text(verbatim: text)
            default: Text("drawer.loadFailed")
            }
        return stateMessage(title: message, detail: nil, systemImage: "exclamationmark.triangle") {
            Button {
                retry()
            } label: {
                Text("common.retry")
            }
            .buttonStyle(SecondaryButtonStyle())
            .accessibilityIdentifier("drawer.retry")
        }
    }

    /// The message and its actions sit at the top and scroll together when the room is short.
    private func stateMessage<Actions: View>(
        title: Text, detail: Text?, systemImage: String, @ViewBuilder actions: () -> Actions
    ) -> some View {
        let isCompact = verticalSizeClass == .compact
        let showsIcon = !isCompact && !dynamicTypeSize.isAccessibilitySize
        let message = VStack(spacing: 8) {
            if showsIcon {
                Image(systemName: systemImage).font(.yak(.largeTitle)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true).padding(.bottom, 4)
            }
            title.font(isCompact ? .yak(.subheadline, .semibold) : .yak(.headline, .semibold)).multilineTextAlignment(
                .center)
            if let detail {
                detail.font(isCompact ? .yak(.footnote) : .yak(.subheadline)).foregroundStyle(Color.yakMuted)
                    .multilineTextAlignment(.center)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.horizontal, 24)
        .cappedAtAccessibility2(when: dynamicTypeSize.isAccessibilitySize)
        let actionButtons = VStack(spacing: 10) { actions() }.fixedSize(horizontal: false, vertical: true)
            .cappedAtAccessibility2(when: dynamicTypeSize.isAccessibilitySize)
            .padding(.horizontal, 24)
        // With room, message and actions scroll together at the top. Where the room is short (landscape, large text),
        // the actions are pinned below a message that scrolls, so an action is never under the bottom bar.
        let isShort = verticalSizeClass == .compact || dynamicTypeSize.isAccessibilitySize
        return Group {
            if isShort {
                VStack(spacing: isCompact ? 4 : 12) {
                    ScrollView {
                        if headerScrollsWithList { header.frame(maxWidth: .infinity, alignment: .leading) }
                        message.padding(.top, isCompact ? 4 : 8)
                    }
                    .scrollBounceBehavior(.basedOnSize)
                    actionButtons.padding(.bottom, isCompact ? 4 : 8)
                }
            } else {
                ScrollView {
                    VStack(spacing: 16) {
                        message
                        actionButtons
                    }
                    .padding(.top, 40).padding(.bottom, 24)
                }
                .scrollBounceBehavior(.basedOnSize)
            }
        }
        .frame(maxHeight: .infinity, alignment: .top)
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("drawer.message")
    }

    // MARK: Bottom bar

    private var bottomBar: some View {
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout(spacing: 8))
        return layout {
            HStack(spacing: 6) {
                Image(systemName: "magnifyingglass").font(.yak(.body)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
                TextField(
                    "drawer.search", text: searchBinding, prompt: Text("drawer.search").foregroundStyle(Color.yakMuted)
                )
                .focused($isSearchFocused)
                .submitLabel(.search)
                .autocorrectionDisabled()
                .textInputAutocapitalization(.never)
                .disabled(drawer == nil)
                .accessibilityIdentifier("drawer.search")
                if !(drawer?.search ?? "").isEmpty {
                    Button {
                        drawer?.search = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill").foregroundStyle(Color.yakMuted)
                            .frame(minWidth: 40, minHeight: 44).contentShape(.rect)
                    }
                    .accessibilityLabel("drawer.clearSearch")
                    .accessibilityIdentifier("drawer.clearSearch")
                }
            }
            .padding(.horizontal, Design.Space.medium)
            .frame(minHeight: Design.Size.bar)
            .background(Color.yakCard, in: .rect(cornerRadius: Design.Radius.control))
            .overlay(
                RoundedRectangle(cornerRadius: Design.Radius.control)
                    .strokeBorder(Color.yakBorderStrong, lineWidth: Design.Space.line))
            HStack(spacing: 8) {
                Button {
                    scene.openNewThread(repo: nil, text: nil)
                } label: {
                    Image(systemName: "square.and.pencil").font(.yak(.body, .semibold))
                        .foregroundStyle(Color.yakAccentInk)
                        .frame(minWidth: Design.Size.bar, minHeight: Design.Size.bar)
                        .background(Color.yakAccent, in: .rect(cornerRadius: Design.Radius.control))
                        .contentShape(.rect(cornerRadius: Design.Radius.control))
                }
                .accessibilityLabel("shell.startThread")
                .accessibilityIdentifier("drawer.newThread")
            }
        }
        .padding(.horizontal, Design.Space.medium).padding(.vertical, Design.Space.snug)
        .background(Color.yakSidebar)
        .overlay(alignment: .top) { Rectangle().fill(Color.yakLine).frame(height: Design.Space.line) }
    }

    private var searchBinding: Binding<String> {
        Binding(get: { drawer?.search ?? "" }, set: { drawer?.search = $0 })
    }
}

/// Nothing for the first 300 ms, then six rows of shapes in the layout of the real ones. A slow first load adds
/// a line and Retry above them.
private struct DrawerLoading: View {
    let isSlow: Bool
    let retry: () -> Void
    @State private var showsSkeleton = false
    /// The session may still be starting, so the list's own slow state is not the only clock.
    @State private var hasWaitedLong = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    var body: some View {
        ScrollView { loadingContent }.scrollBounceBehavior(.basedOnSize)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .task {
                do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
                withAnimation(Motion.resolve(Motion.reveal, reduceMotion: reduceMotion)) { showsSkeleton = true }
            }
            .task {
                do { try await Task.sleep(for: ThreadListFeed.slowAfter) } catch { return }
                withAnimation(Motion.resolve(Motion.content, reduceMotion: reduceMotion)) { hasWaitedLong = true }
            }
    }

    private var loadingContent: some View {
        VStack(alignment: .leading, spacing: 18) {
            if isSlow || hasWaitedLong {
                VStack(alignment: .leading, spacing: 8) {
                    Text("drawer.slow").font(.yak(.footnote)).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                    Button(action: retry) { Text("common.retry") }.buttonStyle(SecondaryButtonStyle())
                        .accessibilityIdentifier("drawer.retry")
                }
            }
            if showsSkeleton || isSlow || hasWaitedLong {
                VStack(alignment: .leading, spacing: 18) {
                    ForEach(0..<6, id: \.self) { index in row(index) }
                }
                .shimmering()
                .accessibilityElement(children: .ignore)
                .accessibilityLabel("drawer.loading")
                .accessibilityIdentifier("drawer.loading")
            }
        }
        .padding(20)
        .frame(maxWidth: .infinity, alignment: .leading)
    }

    private func row(_ index: Int) -> some View {
        HStack(alignment: .top, spacing: 12) {
            Circle().fill(Color.primary.opacity(0.1)).frame(width: 12, height: 12).padding(.top, 4)
            VStack(alignment: .leading, spacing: 8) {
                RoundedRectangle(cornerRadius: 5).fill(Color.primary.opacity(0.1)).frame(height: 14)
                    .frame(maxWidth: index % 2 == 0 ? .infinity : 220, alignment: .leading)
                RoundedRectangle(cornerRadius: 5).fill(Color.primary.opacity(0.07)).frame(width: 120, height: 11)
            }
        }
    }
}

extension View {
    /// Stops the text from growing past accessibility2 where `applies`, so the list or message keeps room. Without
    /// it the view is untouched, so an ordinary size keeps the full range.
    @ViewBuilder fileprivate func cappedAtAccessibility2(when applies: Bool) -> some View {
        if applies {
            dynamicTypeSize(...DynamicTypeSize.accessibility2)
        } else {
            self
        }
    }
}
