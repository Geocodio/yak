import Foundation
import Testing

@testable import YakKit

private func makeCache(_ clock: ManualClock = ManualClock()) -> Cache {
    Cache(instance: UUID(), files: InMemoryFileStore(), clock: clock)
}

@Suite struct ListCacheTests {
    @Test func observationListsLiveApartFromThreadLists() async throws {
        let cache = makeCache()
        let page = try decoded(ObservationPage.self, "scenario-observations")
        await cache.saveFirstPage(FirstPage(rows: page.observations, nextPage: 2), key: .observations)
        await cache.saveThreadList(try decoded(ThreadList.self, "scenario-thread-list"), filter: .mine)
        let saved: FirstPage<ObservationRow>? = await cache.firstPage(.observations)
        #expect(saved?.rows.map(\.id) == page.observations.map(\.id) && saved?.nextPage == 2)
        #expect(await cache.threadList(.mine) != nil)
    }

    @Test func eraseAllRemovesTheListCopies() async throws {
        let cache = makeCache()
        await cache.saveFirstPage(FirstPage(rows: [ObservationRow](), nextPage: nil), key: .observations)
        await cache.eraseAll()
        let page: FirstPage<ObservationRow>? = await cache.firstPage(.observations)
        #expect(page == nil)
    }
}
