import Testing

@testable import Yak

struct HeldPlaceTests {
    private let held = HeldPlace(id: "anchor", fraction: 0.5, awaitedIDs: ["a", "b"])

    @Test func theHoldStaysWhileADocumentOfThePageIsMissing() {
        // No clock is involved: however long the document takes, the status is the same.
        for _ in 0..<3 {
            #expect(held.status(hasDocument: { $0 == "a" }, hasRow: { _ in true }) == .holding)
        }
    }

    @Test func theHoldEndsWhenTheLastDocumentArrives() {
        #expect(held.status(hasDocument: { _ in true }, hasRow: { _ in true }) == .complete)
    }

    @Test func aPageWithoutTextRowsEndsAtOnce() {
        let place = HeldPlace(id: "anchor", fraction: 0, awaitedIDs: [])
        #expect(place.status(hasDocument: { _ in false }, hasRow: { _ in true }) == .complete)
    }

    @Test func theHoldEndsWhenTheAnchorRowIsGone() {
        #expect(held.status(hasDocument: { _ in false }, hasRow: { $0 != "anchor" }) == .anchorGone)
        #expect(held.status(hasDocument: { _ in true }, hasRow: { $0 != "anchor" }) == .anchorGone)
    }
}
