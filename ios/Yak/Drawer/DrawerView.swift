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
    let isCompactWidth: Bool
    let openAccount: () -> Void
    let openSwitcher: () -> Void
    /// Reloads the list, or starts the session again while it is still signing in.
    let retry: () -> Void

    @FocusState private var isSearchFocused: Bool
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
            content.frame(maxHeight: .infinity, alignment: .top)
        }
        // Whatever sits above the bottom bar is clipped to the room left, so the bar is always fully on screen.
        .frame(maxHeight: .infinity, alignment: .top)
        .clipped()
        .safeAreaInset(edge: .bottom, spacing: 0) {
            VStack(spacing: 0) {
                actionLine(drawer)
                bottomBar
            }
        }
        .animation(.spring(duration: 0.3), value: drawer?.actionLine)
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
        .background(Color(.secondarySystemBackground))
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
                Image(systemName: "chevron.up.chevron.down").font(.footnote.weight(.semibold))
                    .foregroundStyle(Color.yakMuted)
            }
            .frame(minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.plain)
        .accessibilityLabel("drawer.switcher \(instance.name)")
        .layoutPriority(1)
        .accessibilityIdentifier("drawer.switcher")
    }

    private var accountButton: some View {
        Button(action: openAccount) {
            Text(initials).font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
                .lineLimit(1).fixedSize().dynamicTypeSize(...DynamicTypeSize.accessibility1)
                .padding(8).frame(minWidth: 36, minHeight: 36)
                .background(Color.primary.opacity(0.1), in: .capsule)
                .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .buttonStyle(.plain)
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
            name.font(.title3.bold()).fixedSize(horizontal: false, vertical: true)
        } else {
            name.font(.title2.bold()).lineLimit(2).minimumScaleFactor(0.6).fixedSize(horizontal: false, vertical: true)
        }
    }

    private func banner(_ name: String) -> some View {
        HStack {
            Text("signedIn.signedInAs \(name)").font(.subheadline).fixedSize(horizontal: false, vertical: true)
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

    // MARK: Content

    @ViewBuilder private var content: some View {
        if let drawer, let state = drawer.state, state.list != nil, let shown = drawer.content {
            VStack(spacing: 0) {
                if state.isOffline { notice(Text("drawer.offlineSaved"), systemImage: "wifi.slash") }
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
                                .listRowInsets(.init())
                                .listRowSeparator(.hidden)
                                .listRowBackground(Color.clear)
                                .transition(.opacity)
                        }
                    } header: {
                        Text(title(of: section.kind)).font(.subheadline.weight(.semibold))
                            .foregroundStyle(section.kind == .needsYou ? Color.yakAction : Color.yakMuted)
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
            .scrollContentBackground(.hidden)
            .scrollDismissesKeyboard(.interactively)
            .animation(.spring(duration: 0.35), value: drawer.content)
            .accessibilityIdentifier("drawer.list")
        }
    }

    /// A sub-thread row also offers a long press menu and swipe actions; a root row is the plain row.
    @ViewBuilder private func rowView(_ row: DrawerRow, now: Date, drawer: DrawerModel) -> some View {
        let plain = DrawerRowView(
            row: row, now: now, open: { open(row) }, toggle: { drawer.toggle(row.id) },
            isRunning: drawer.runningChildID.map { "thread:\($0)" } == row.id)
        if row.depth > 0, case .thread = row.target {
            let menu = drawer.childMenu(row)
            plain
                .contextMenu {
                    childMenuItems(row, menu: menu, drawer: drawer)
                } preview: {
                    DrawerRowView(row: row, now: now, open: {}, toggle: {}, isRunning: false)
                        .frame(minWidth: 280)
                        .onAppear { drawer.menuOpening(row) }
                }
                .swipeActions(edge: .trailing, allowsFullSwipe: false) {
                    if case .ready(let actions) = menu, !drawer.isOffline {
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
                    Button {
                        open(row)
                    } label: {
                        Text("overview.child.open")
                    }.tint(Color(.systemGray2))
                }
        } else {
            plain
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

    /// The server's sentence about a refused action, above the bottom controls.
    @ViewBuilder private func actionLine(_ drawer: DrawerModel?) -> some View {
        if let line = drawer?.actionLine {
            Text(line).font(.footnote.weight(.medium)).foregroundStyle(.primary)
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
                .padding(.horizontal, 20).padding(.vertical, 10)
                .background(Color.primary.opacity(0.06))
                .accessibilityIdentifier("drawer.actionLine")
                .transition(.opacity)
        }
    }

    private func title(of kind: DrawerSection.Kind) -> LocalizedStringKey {
        switch kind {
        case .notSentYet: "drawer.notSentYet"
        case .needsYou: "drawer.needsYou"
        case .starred: "drawer.starred"
        case .today: "drawer.today"
        case .thisWeek: "drawer.thisWeek"
        case .older: "drawer.older"
        }
    }

    private func open(_ row: DrawerRow) {
        switch row.target {
        case .thread(let id):
            isSearchFocused = false
            scene.open(thread: id, isCompactWidth: isCompactWidth)
        case .task(let id): model.browserURL = instance.url.appending(path: "tasks").appending(path: id)
        case .unsentThread(let id):
            guard let item = drawer?.unsentItem(id), case .newThread(let draft) = item.target else {
                scene.openNewThread(repo: nil, text: nil)
                return
            }
            scene.openNewThread(repo: draft.repo, text: item.text)
        }
    }

    // MARK: States

    private func notice(_ text: Text, systemImage: String) -> some View {
        Label {
            text.font(.footnote).fixedSize(horizontal: false, vertical: true)
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
                Image(systemName: systemImage).font(.largeTitle).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true).padding(.bottom, 4)
            }
            title.font(isCompact ? .subheadline.weight(.semibold) : .headline).multilineTextAlignment(.center)
            if let detail {
                detail.font(isCompact ? .footnote : .subheadline).foregroundStyle(Color.yakMuted)
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
        // With the keyboard up at a large text size the filter shows as an icon, so the bar stays short.
        let isOneRow = dynamicTypeSize.isAccessibilitySize
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout(spacing: 8))
        return layout {
            HStack(spacing: 6) {
                Image(systemName: "magnifyingglass").font(.body).foregroundStyle(Color.yakMuted)
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
            .padding(.leading, 12)
            .frame(minHeight: 44)
            .background(Color.primary.opacity(0.08), in: .rect(cornerRadius: 12))
            HStack(spacing: 8) {
                filterMenu(showsName: !isOneRow)
                Button {
                    scene.openNewThread(repo: nil, text: nil)
                } label: {
                    Image(systemName: "square.and.pencil").font(.body.weight(.semibold))
                        .foregroundStyle(Color.yakOnRust)
                        .frame(minWidth: 44, minHeight: 44).background(Color.yakRust, in: .rect(cornerRadius: 12))
                        .contentShape(.rect)
                }
                .accessibilityLabel("shell.startThread")
                .accessibilityIdentifier("drawer.newThread")
            }
        }
        .padding(.horizontal, 12).padding(.vertical, 8)
        .background(Color(.secondarySystemBackground))
        .overlay(alignment: .top) { Divider() }
    }

    private var searchBinding: Binding<String> {
        Binding(get: { drawer?.search ?? "" }, set: { drawer?.search = $0 })
    }

    private func filterMenu(showsName: Bool) -> some View {
        let filter = drawer?.filter ?? scene.state.filter
        return Menu {
            Picker("drawer.filter.title", selection: filterBinding) {
                Label("drawer.filter.mine", systemImage: "person").tag(ThreadListFilter.mine)
                Label("drawer.filter.yak", systemImage: "sparkles").tag(ThreadListFilter.yak)
                Label("drawer.filter.everyone", systemImage: "person.2").tag(ThreadListFilter.everyone)
            }
        } label: {
            HStack(spacing: 4) {
                if showsName {
                    Text(name(of: filter)).font(.subheadline.weight(.semibold))
                } else {
                    Image(systemName: icon(of: filter)).font(.body.weight(.semibold))
                }
                Image(systemName: "chevron.down").font(.caption2.weight(.bold)).accessibilityHidden(true)
            }
            .foregroundStyle(.primary)
            .padding(.horizontal, 12).frame(minHeight: 44)
            .background(Color.primary.opacity(0.08), in: .rect(cornerRadius: 12))
            .contentShape(.rect)
        }
        .tint(.primary)
        .accessibilityLabel(label(of: filter))
        .accessibilityIdentifier("drawer.filter")
    }

    private var filterBinding: Binding<ThreadListFilter> {
        Binding(
            get: { scene.state.filter },
            set: { next in
                if let drawer {
                    Task { await drawer.setFilter(next) }
                } else {
                    scene.setFilter(next)
                }
            })
    }

    private func name(of filter: ThreadListFilter) -> LocalizedStringKey {
        switch filter {
        case .mine: "drawer.filter.mine"
        case .yak: "drawer.filter.yak"
        case .everyone: "drawer.filter.everyone"
        }
    }

    private func icon(of filter: ThreadListFilter) -> String {
        switch filter {
        case .mine: "person"
        case .yak: "sparkles"
        case .everyone: "person.2"
        }
    }

    private func label(of filter: ThreadListFilter) -> LocalizedStringKey {
        switch filter {
        case .mine: "drawer.filter.label.mine"
        case .yak: "drawer.filter.label.yak"
        case .everyone: "drawer.filter.label.everyone"
        }
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
    @State private var isDim = false

    var body: some View {
        ScrollView { loadingContent }.scrollBounceBehavior(.basedOnSize)
            .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
            .task {
                do { try await Task.sleep(for: .milliseconds(300)) } catch { return }
                withAnimation(.easeIn(duration: 0.2)) { showsSkeleton = true }
                guard !reduceMotion else { return }
                withAnimation(.easeInOut(duration: 0.9).repeatForever()) { isDim = true }
            }
            .task {
                do { try await Task.sleep(for: ThreadListFeed.slowAfter) } catch { return }
                withAnimation(.spring) { hasWaitedLong = true }
            }
    }

    private var loadingContent: some View {
        VStack(alignment: .leading, spacing: 18) {
            if isSlow || hasWaitedLong {
                VStack(alignment: .leading, spacing: 8) {
                    Text("drawer.slow").font(.footnote).foregroundStyle(Color.yakMuted)
                        .fixedSize(horizontal: false, vertical: true)
                    Button(action: retry) { Text("common.retry") }.buttonStyle(SecondaryButtonStyle())
                        .accessibilityIdentifier("drawer.retry")
                }
            }
            if showsSkeleton || isSlow || hasWaitedLong {
                VStack(alignment: .leading, spacing: 18) {
                    ForEach(0..<6, id: \.self) { index in row(index) }
                }
                .opacity(isDim ? 0.5 : 1)
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
