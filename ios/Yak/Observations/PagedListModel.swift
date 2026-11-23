import Foundation
import Observation
import YakKit

/// A list that loads page by page: the Observations list. Views read finished snapshots; the
/// feed does the loading, the saved first page and the paging.
@MainActor @Observable
final class PagedListModel<
    Query: Equatable & Codable & Sendable, Row: Codable & Equatable & Sendable & Identifiable,
    Page: Decodable & Sendable
> where Row.ID: Hashable & Sendable {
    /// How long the typing must stop before the search is sent.
    static var searchDelay: Duration { .milliseconds(300) }

    private(set) var snapshot = PagedFeed<Query, Row>.Snapshot()
    /// The newest page the server answered, for the counts and the filter options it carries.
    private(set) var latestPage: Page?
    var query: Query {
        didSet { if query != oldValue { Task { [weak self] in if let self { await feed?.setQuery(self.query) } } } }
    }
    /// The search text as typed; the query follows it 300 ms after the last change.
    var search = "" {
        didSet { if search != oldValue { scheduleSearch() } }
    }

    @ObservationIgnored private let session: InstanceSession
    @ObservationIgnored private let cacheKey: TaskListCacheKey
    @ObservationIgnored private let endpoint: @Sendable (Query, Int) -> Endpoint<Page>
    @ObservationIgnored private let rows: @Sendable (Page) -> [Row]
    @ObservationIgnored private let nextPage: @Sendable (Page) -> Int?
    @ObservationIgnored private let searchKey: WritableKeyPath<Query, String?>
    @ObservationIgnored private var feed: PagedFeed<Query, Row>?
    @ObservationIgnored private var readingTask: Task<Void, Never>?
    @ObservationIgnored private var searchTask: Task<Void, Never>?

    init(
        session: InstanceSession, query: Query, cacheKey: TaskListCacheKey, searchKey: WritableKeyPath<Query, String?>,
        endpoint: @escaping @Sendable (Query, Int) -> Endpoint<Page>, rows: @escaping @Sendable (Page) -> [Row],
        nextPage: @escaping @Sendable (Page) -> Int?
    ) {
        self.session = session
        self.query = query
        self.cacheKey = cacheKey
        self.searchKey = searchKey
        self.endpoint = endpoint
        self.rows = rows
        self.nextPage = nextPage
    }

    /// Opens the feed, shows the saved copy and asks the server. A second call changes nothing.
    func start() async {
        guard feed == nil else { return }
        guard let client = await session.apiClient(), let cache = await session.instanceCache() else {
            snapshot = .init(load: .failed(.offline))
            return
        }
        let endpoint = self.endpoint
        let rows = self.rows
        let nextPage = self.nextPage
        let opened = PagedFeed<Query, Row>(
            query: query, cacheKey: cacheKey,
            fetch: { [weak self] (query, page) async throws(APIFailure) -> (rows: [Row], nextPage: Int?) in
                let answer = try await client.send(endpoint(query, page))
                await self?.note(answer)
                return (rows(answer), nextPage(answer))
            }, cache: cache, clock: session.clock)
        feed = opened
        let stream = await opened.snapshots()
        readingTask = Task { [weak self] in
            for await next in stream { self?.snapshot = next }
        }
        await opened.reload()
    }

    func stop() async {
        readingTask?.cancel()
        searchTask?.cancel()
        readingTask = nil
        searchTask = nil
        let closing = feed
        feed = nil
        await closing?.close()
    }

    func reload() async { await feed?.reload() }
    func loadMore() async { await feed?.loadMore() }

    private func note(_ page: Page) { latestPage = page }

    private func scheduleSearch() {
        searchTask?.cancel()
        let clock = session.clock
        searchTask = Task { [weak self] in
            do { try await clock.sleep(for: Self.searchDelay) } catch { return }
            guard let self else { return }
            let trimmed = search.trimmingCharacters(in: .whitespacesAndNewlines)
            query[keyPath: searchKey] = trimmed.isEmpty ? nil : trimmed
        }
    }
}

typealias ObservationsModel = PagedListModel<ObservationQuery, ObservationRow, ObservationPage>

extension PagedListModel where Query == ObservationQuery, Row == ObservationRow, Page == ObservationPage {
    static func observations(session: InstanceSession) -> ObservationsModel {
        ObservationsModel(
            session: session, query: ObservationQuery(), cacheKey: .observations, searchKey: \.search,
            endpoint: { .observations($0, page: $1) }, rows: { $0.observations }, nextPage: { $0.nextPage })
    }

    var options: ObservationOptions? { latestPage?.options }
}
