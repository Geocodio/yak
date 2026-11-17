import SwiftUI
import YakKit

/// The Observations list, board "Observations": what Yak noticed while it worked, and whether it acted.
struct ObservationsScreen: View {
    let model: ObservationsModel
    let openTask: (String) -> Void
    let openWeb: (URL) -> Void
    let close: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    private var snapshot: PagedFeed<ObservationQuery, ObservationRow>.Snapshot { model.snapshot }

    var body: some View {
        content
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .background(Color(.systemBackground))
            .safeAreaInset(edge: .bottom, spacing: 0) { bottomBar }
            .navigationTitle("observations.title")
            .navigationBarTitleDisplayMode(.large)
            .searchable(text: Bindable(model).search, prompt: Text("observations.search"))
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button(action: close) { Image(systemName: "line.3.horizontal") }
                        .accessibilityLabel("shell.threads")
                        .accessibilityIdentifier("observations.threads")
                }
            }
            .accessibilityIdentifier("observations.list")
            .task {
                await model.start()
            }
    }

    @ViewBuilder private var content: some View {
        if !snapshot.rows.isEmpty {
            VStack(spacing: 0) {
                if snapshot.isOffline || snapshot.isShowingCachedCopy && snapshot.load != .ready {
                    NoticeLine(
                        text: "list.offlineSaved", systemImage: "wifi.slash", identifier: "observations.offlineNotice")
                }
                list
            }
        } else {
            switch snapshot.load {
            case .loading: RowSkeleton(identifier: "observations.loading")
            case .slow:
                StateMessage(
                    title: "list.slow", systemImage: "hourglass", identifier: "observations.slow",
                    actions: { RetryButton(identifier: "observations.retry") { Task { await model.reload() } } })
            case .failed(let problem):
                StateMessage(
                    title: Text("observations.error"),
                    detail: ProblemCopy.message(problem, offline: "observations.offline"),
                    systemImage: problem == .offline ? "wifi.slash" : "exclamationmark.triangle",
                    identifier: "observations.error",
                    actions: { RetryButton(identifier: "observations.retry") { Task { await model.reload() } } })
            case .ready: empty
            }
        }
    }

    private var list: some View {
        List {
            ForEach(snapshot.rows) { row in
                rowView(row)
                    .listRowInsets(.init()).listRowSeparator(.hidden).listRowBackground(Color.clear)
                    .onAppear { if row.id == snapshot.rows.last?.id { Task { await model.loadMore() } } }
            }
            if snapshot.isLoadingMore {
                ProgressView().frame(maxWidth: .infinity).padding(16).listRowSeparator(.hidden)
            } else if snapshot.problem != nil, snapshot.nextPage != nil {
                VStack(spacing: 8) {
                    Text("list.moreFailed").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    RetryButton(identifier: "observations.retryMore") { Task { await model.loadMore() } }
                }
                .padding(16).listRowSeparator(.hidden)
            }
        }
        .listStyle(.plain)
        .refreshable { await model.reload() }
    }

    @ViewBuilder private func rowView(_ row: ObservationRow) -> some View {
        let label = rowContent(row)
        switch ObservationRowAction.of(row) {
        case .task(let taskID):
            Button {
                openTask(taskID)
            } label: {
                label
            }.buttonStyle(.plain)
                .accessibilityIdentifier("observations.row.\(row.id)")
        case .web(let url):
            Button {
                openWeb(url)
            } label: {
                label
            }.buttonStyle(.plain)
                .accessibilityIdentifier("observations.row.\(row.id)")
        case .none:
            label.accessibilityElement(children: .combine).accessibilityIdentifier("observations.row.\(row.id)")
        }
    }

    private func rowContent(_ row: ObservationRow) -> some View {
        let isActed = row.outcome == .acted
        let outcome = isActed ? String(localized: "observations.acted") : String(localized: "observations.declined")
        let subtitle = row.repo.map { String(localized: "observations.subtitle \(outcome) \($0)") } ?? outcome
        return HStack(alignment: .top, spacing: 12) {
            Circle().fill(isActed ? Color.green : Color.primary.opacity(0.35)).frame(width: 10, height: 10)
                .padding(.top, 7).accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 4) {
                Text(verbatim: row.summary).font(.body).foregroundStyle(.primary).multilineTextAlignment(.leading)
                Text(verbatim: subtitle).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                Text(verbatim: row.createdAgo).font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
            }
            Spacer(minLength: 0)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(.horizontal, 20).padding(.vertical, 10).frame(minHeight: 44).contentShape(.rect)
    }

    @ViewBuilder private var empty: some View {
        if model.query.outcome != nil || model.query.repo != nil || model.query.search != nil {
            StateMessage(
                title: "observations.noMatch", systemImage: "eye", identifier: "observations.noMatch",
                actions: {
                    Button {
                        model.search = ""
                        model.query = ObservationQuery()
                    } label: {
                        Text("observations.showAll")
                    }
                    .buttonStyle(SecondaryButtonStyle()).accessibilityIdentifier("observations.showAll")
                })
        } else {
            StateMessage(
                title: "observations.empty.title", detail: "observations.empty.detail", systemImage: "eye",
                identifier: "observations.empty")
        }
    }

    // MARK: Bottom bar

    private var bottomBar: some View {
        let tabs = HStack(spacing: 8) {
            outcomeButton(nil, title: "observations.filter.all", identifier: "observations.filter.all")
            outcomeButton(.acted, title: "observations.acted", identifier: "observations.filter.acted")
            outcomeButton(.declined, title: "observations.declined", identifier: "observations.filter.declined")
        }
        return Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: 8) {
                    tabs
                    repositoryMenu
                }
            } else {
                HStack(spacing: 8) {
                    tabs
                    Spacer(minLength: 0)
                    repositoryMenu
                }
            }
        }
        .padding(.horizontal, 16).padding(.vertical, 8)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color(.systemBackground))
        .overlay(alignment: .top) { Divider() }
    }

    private func outcomeButton(_ outcome: ObservationOutcome?, title: LocalizedStringKey, identifier: String)
        -> some View
    {
        let isSelected = model.query.outcome == outcome
        return Button {
            var next = model.query
            next.outcome = outcome
            model.query = next
        } label: {
            Text(title).font(.subheadline.weight(.semibold)).foregroundStyle(.primary)
                .padding(.horizontal, 12).frame(minHeight: 44)
                .background(isSelected ? Color.primary.opacity(0.12) : Color.clear, in: .capsule)
                .contentShape(.capsule)
        }
        .buttonStyle(.plain)
        .accessibilityAddTraits(isSelected ? .isSelected : [])
        .accessibilityIdentifier(identifier)
    }

    private var repositoryMenu: some View {
        Menu {
            Menu("tasks.filter.repository") {
                ForEach(model.options?.repos ?? [], id: \.self) { repo in
                    Button(repo) {
                        var next = model.query
                        next.repo = repo
                        model.query = next
                    }
                }
            }
            if model.query.repo != nil {
                Button("tasks.clearFilters") {
                    var next = model.query
                    next.repo = nil
                    model.query = next
                }
            }
        } label: {
            Image(
                systemName: model.query.repo == nil
                    ? "line.3.horizontal.decrease.circle" : "line.3.horizontal.decrease.circle.fill"
            )
            .font(.title3).frame(minWidth: 44, minHeight: 44).contentShape(.rect)
        }
        .foregroundStyle(.primary)
        .accessibilityLabel("tasks.filter")
        .accessibilityIdentifier("observations.filter")
    }
}

/// What a tap on an observation does: open its task, open its reference, or nothing when it has neither.
enum ObservationRowAction: Equatable {
    case task(String)
    case web(URL)
    case none

    static func of(_ row: ObservationRow) -> ObservationRowAction {
        if let taskID = row.taskId { return .task(taskID) }
        if let url = WebLinkRule.safeURL(row.referenceUrl) { return .web(url) }
        return .none
    }
}
