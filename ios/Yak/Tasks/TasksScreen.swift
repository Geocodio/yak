import SwiftUI
import YakKit

/// The Tasks list, board "Tasks": the title, the rows, and at the bottom the tabs and the filter menu.
struct TasksScreen: View {
    let model: TaskListModel
    let openTask: (String) -> Void
    let close: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        content
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground))
            .safeAreaInset(edge: .bottom, spacing: 0) { bottomBar }
            .navigationTitle("tasks.title")
            .navigationBarTitleDisplayMode(.large)
            .searchable(text: Bindable(model).search, prompt: Text("tasks.search"))
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button(action: close) { Image(systemName: "line.3.horizontal") }
                        .accessibilityLabel("shell.threads")
                        .accessibilityIdentifier("tasks.threads")
                }
            }
            .accessibilityIdentifier("tasks.list")
            .task {
                await model.start()
            }
    }

    private var snapshot: PagedFeed<TaskListQuery, TaskRow>.Snapshot { model.snapshot }

    @ViewBuilder private var content: some View {
        if !snapshot.rows.isEmpty {
            VStack(spacing: 0) {
                if snapshot.isOffline || snapshot.isShowingCachedCopy && snapshot.load != .ready {
                    NoticeLine(text: "list.offlineSaved", systemImage: "wifi.slash", identifier: "tasks.offlineNotice")
                }
                list
            }
        } else {
            switch snapshot.load {
            case .loading: RowSkeleton(identifier: "tasks.loading")
            case .slow:
                StateMessage(
                    title: "list.slow", systemImage: "hourglass", identifier: "tasks.slow",
                    actions: { RetryButton(identifier: "tasks.retry") { Task { await model.reload() } } })
            case .failed(let problem): failure(problem)
            case .ready: empty
            }
        }
    }

    private var list: some View {
        List {
            ForEach(snapshot.rows) { row in
                TaskRowView(row: row) { openTask(row.id) }
                    .listRowInsets(.init()).listRowSeparator(.hidden).listRowBackground(Color.clear)
                    .onAppear { if row.id == snapshot.rows.last?.id { Task { await model.loadMore() } } }
            }
            footer.listRowInsets(.init()).listRowSeparator(.hidden).listRowBackground(Color.clear)
        }
        .listStyle(.plain)
        .refreshable { await model.reload() }
    }

    @ViewBuilder private var footer: some View {
        if snapshot.isLoadingMore {
            ProgressView().frame(maxWidth: .infinity).padding(16).accessibilityIdentifier("tasks.loadingMore")
        } else if snapshot.problem != nil, snapshot.nextPage != nil {
            VStack(spacing: 8) {
                Text("list.moreFailed").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                RetryButton(identifier: "tasks.retryMore") { Task { await model.loadMore() } }
            }
            .padding(16)
        }
    }

    private func failure(_ problem: ThreadProblem) -> some View {
        StateMessage(
            title: Text("tasks.error"), detail: ProblemCopy.message(problem, offline: "tasks.offline"),
            systemImage: problem == .offline ? "wifi.slash" : "exclamationmark.triangle", identifier: "tasks.error",
            actions: { RetryButton(identifier: "tasks.retry") { Task { await model.reload() } } })
    }

    @ViewBuilder private var empty: some View {
        if hasFilters {
            StateMessage(
                title: "tasks.noMatch", systemImage: "line.3.horizontal.decrease.circle", identifier: "tasks.noMatch",
                actions: {
                    Button {
                        clearFilters()
                    } label: {
                        Text("tasks.clearFilters")
                    }
                    .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("tasks.clearFilters")
                })
        } else {
            switch model.query.tab {
            case .tasks:
                StateMessage(
                    title: "tasks.empty.title", detail: "tasks.empty.detail", systemImage: "checklist",
                    identifier: "tasks.empty")
            case .reviews:
                StateMessage(title: "tasks.empty.reviews", systemImage: "checklist", identifier: "tasks.empty")
            case .setup:
                StateMessage(title: "tasks.empty.setup", systemImage: "checklist", identifier: "tasks.empty")
            }
        }
    }

    private var hasFilters: Bool {
        let query = model.query
        return query.status != nil || query.source != nil || query.repo != nil || query.pullRequest != nil
            || query.search != nil
    }

    private func clearFilters() {
        var cleared = TaskListQuery()
        cleared.tab = model.query.tab
        model.search = ""
        model.query = cleared
    }

    // MARK: Bottom bar

    private var bottomBar: some View {
        let controls = HStack(spacing: 8) {
            ForEach(TaskTab.allCases, id: \.self) { tab in tabButton(tab) }
            Spacer(minLength: 0)
            filterMenu
        }
        return Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: 8) {
                    HStack(spacing: 8) { ForEach(TaskTab.allCases, id: \.self) { tab in tabButton(tab) } }
                    filterMenu
                }
            } else {
                controls
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .overlay(alignment: .top) { Divider() }
    }

    private func tabButton(_ tab: TaskTab) -> some View {
        let isSelected = model.query.tab == tab
        let count: Int? = {
            switch tab {
            case .tasks: model.counts?.tasks
            case .reviews: model.counts?.reviews
            case .setup: nil
            }
        }()
        return Button {
            var next = model.query
            next.tab = tab
            model.query = next
        } label: {
            HStack(spacing: 4) {
                Text(label(of: tab))
                if let count, count > 0 { Text(verbatim: "\(count)") }
            }
            .font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
            .padding(.horizontal, 12).frame(minHeight: 44)
            .background(isSelected ? Color.primary.opacity(0.12) : Color.clear, in: .capsule)
            .contentShape(.capsule)
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(isSelected ? .isSelected : [])
        .accessibilityIdentifier("tasks.tab.\(tab.rawValue)")
    }

    private func label(of tab: TaskTab) -> LocalizedStringKey {
        switch tab {
        case .tasks: "tasks.tab.tasks"
        case .reviews: "tasks.tab.reviews"
        case .setup: "tasks.tab.setup"
        }
    }

    private var filterMenu: some View {
        Menu {
            Menu("tasks.filter.status") {
                ForEach(
                    [
                        ("running", "tasks.filter.status.running" as LocalizedStringKey),
                        ("awaiting_co_owner", "tasks.filter.status.awaitingCoOwner"),
                        ("awaiting_clarification", "tasks.filter.status.awaitingClarification"),
                        ("success", "tasks.filter.status.success"), ("failed", "tasks.filter.status.failed"),
                    ], id: \.0
                ) { status, title in
                    Button(title) {
                        var next = model.query
                        next.status = TaskStatus(rawValue: status)
                        model.query = next
                    }
                }
            }
            if model.query.tab == .tasks, let sources = model.options?.sources, !sources.isEmpty {
                Menu("tasks.filter.source") {
                    ForEach(sources, id: \.self) { source in
                        Button(source) {
                            var next = model.query
                            next.source = source
                            model.query = next
                        }
                    }
                }
            }
            if let repos = model.options?.repos, !repos.isEmpty {
                Menu("tasks.filter.repository") {
                    ForEach(repos, id: \.self) { repo in
                        Button(repo) {
                            var next = model.query
                            next.repo = repo
                            model.query = next
                        }
                    }
                }
            }
            Menu("tasks.filter.pullRequest") {
                ForEach(
                    [
                        ("open", "tasks.filter.pullRequest.open" as LocalizedStringKey),
                        ("merged", "tasks.filter.pullRequest.merged"), ("closed", "tasks.filter.pullRequest.closed"),
                        ("none", "tasks.filter.pullRequest.none"),
                    ], id: \.0
                ) { state, title in
                    Button(title) {
                        var next = model.query
                        next.pullRequest = state
                        model.query = next
                    }
                }
            }
            if hasFilters { Button("tasks.clearFilters", action: clearFilters) }
        } label: {
            Image(
                systemName: hasFilters ? "line.3.horizontal.decrease.circle.fill" : "line.3.horizontal.decrease.circle"
            )
            .font(.title3).frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .foregroundStyle(.primary)
        .accessibilityLabel("tasks.filter")
        .accessibilityIdentifier("tasks.filter")
    }
}
