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
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(spacing: 0) {
            header
            if let name = scene.justPairedUserName { banner(name) }
            content
        }
        .safeAreaInset(edge: .bottom, spacing: 0) { bottomBar }
        .background(Color(.secondarySystemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityIdentifier("drawer")
        .onChange(of: scene.searchFocusRequest) { isSearchFocused = true }
    }

    // MARK: Header

    private var header: some View {
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 4)) : AnyLayout(HStackLayout(spacing: 8))
        return layout {
            Button(action: openSwitcher) {
                HStack(spacing: 6) {
                    Text(instance.name).font(.title2.bold()).multilineTextAlignment(.leading)
                        .fixedSize(horizontal: false, vertical: true)
                    Image(systemName: "chevron.up.chevron.down").font(.footnote.weight(.semibold))
                        .foregroundStyle(Color.yakMuted)
                }
                .frame(minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("drawer.switcher \(instance.name)")
            .accessibilityIdentifier("drawer.switcher")
            if !dynamicTypeSize.isAccessibilitySize { Spacer(minLength: 0) }
            Button(action: openAccount) {
                Text(initials).font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
                    .padding(8).frame(minWidth: 36, minHeight: 36)
                    .background(Color.primary.opacity(0.1), in: .capsule)
                    .frame(minWidth: 44, minHeight: 44).contentShape(.rect)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("drawer.accountLabel \(instance.userName)")
            .accessibilityIdentifier("drawer.account")
        }
        .padding(.horizontal, 20)
        .padding(.top, 4)
    }

    private var initials: String {
        let letters = instance.userName.split(separator: " ").prefix(2).compactMap(\.first)
        return letters.isEmpty ? "?" : String(letters).uppercased()
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
        let now = Date()
        return List {
            ForEach(sections) { section in
                Section {
                    ForEach(section.rows) { row in
                        DrawerRowView(
                            row: row, now: now, open: { open(row) }, toggle: { drawer.toggle(row.id) }
                        )
                        .listRowInsets(.init())
                        .listRowSeparator(.hidden)
                        .listRowBackground(Color.clear)
                        .transition(.opacity)
                    }
                } header: {
                    Text(title(of: section.kind)).font(.subheadline.weight(.semibold))
                        .foregroundStyle(section.kind == .needsYou ? Color.yakAction : Color.yakMuted)
                        .textCase(nil)
                        .padding(.leading, 20)
                        .accessibilityAddTraits(.isHeader)
                }
                .listSectionSeparator(.hidden)
            }
        }
        .listStyle(.plain)
        .scrollContentBackground(.hidden)
        .scrollDismissesKeyboard(.interactively)
        .animation(.spring(duration: 0.35), value: drawer.content)
        .accessibilityIdentifier("drawer.list")
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

    private func stateMessage<Actions: View>(
        title: Text, detail: Text?, systemImage: String, @ViewBuilder actions: () -> Actions
    ) -> some View {
        ScrollView {
            VStack(spacing: 12) {
                Image(systemName: systemImage).font(.largeTitle).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
                title.font(.headline).multilineTextAlignment(.center)
                if let detail {
                    detail.font(.subheadline).foregroundStyle(Color.yakMuted).multilineTextAlignment(.center)
                }
                VStack(spacing: 10) { actions() }.padding(.top, 8)
            }
            .frame(maxWidth: .infinity)
            .padding(.horizontal, 24).padding(.top, 40).padding(.bottom, 24)
        }
        .accessibilityIdentifier("drawer.message")
    }

    // MARK: Bottom bar

    private var bottomBar: some View {
        let layout =
            dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout(spacing: 8))
        return layout {
            HStack(spacing: 6) {
                Image(systemName: "magnifyingglass").font(.system(size: 17)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
                TextField(
                    "drawer.search", text: searchBinding, prompt: Text("drawer.search").foregroundStyle(Color.yakMuted)
                )
                .focused($isSearchFocused)
                .submitLabel(.search)
                .autocorrectionDisabled()
                .textInputAutocapitalization(.never)
                .accessibilityIdentifier("drawer.search")
                if !(drawer?.search ?? "").isEmpty {
                    Button {
                        drawer?.search = ""
                    } label: {
                        Image(systemName: "xmark.circle.fill").foregroundStyle(Color.yakMuted)
                            .frame(width: 44, height: 44).contentShape(.rect)
                    }
                    .accessibilityLabel("drawer.clearSearch")
                    .accessibilityIdentifier("drawer.clearSearch")
                }
            }
            .padding(.leading, 12)
            .frame(minHeight: 44)
            .background(Color.primary.opacity(0.08), in: .rect(cornerRadius: 12))
            HStack(spacing: 8) {
                filterMenu
                Button {
                    scene.openNewThread(repo: nil, text: nil)
                } label: {
                    Image(systemName: "square.and.pencil").font(.system(size: 17, weight: .semibold))
                        .foregroundStyle(Color.yakOnRust)
                        .frame(width: 44, height: 44).background(Color.yakRust, in: .rect(cornerRadius: 12))
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

    private var filterMenu: some View {
        let filter = drawer?.filter ?? scene.state.filter
        return Menu {
            Picker("drawer.filter.title", selection: filterBinding) {
                Label("drawer.filter.mine", systemImage: "person").tag(ThreadListFilter.mine)
                Label("drawer.filter.yak", systemImage: "sparkles").tag(ThreadListFilter.yak)
                Label("drawer.filter.everyone", systemImage: "person.2").tag(ThreadListFilter.everyone)
            }
        } label: {
            Image(systemName: icon(of: filter)).font(.system(size: 17, weight: .semibold)).foregroundStyle(.primary)
                .frame(width: 44, height: 44).background(Color.primary.opacity(0.08), in: .rect(cornerRadius: 12))
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
