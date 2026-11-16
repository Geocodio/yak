import Foundation
import Testing
import YakKit

@testable import Yak

private func observationFixture() async throws -> TasksFixture {
    try await TasksFixture { route, _, _ in
        route == "GET observations" ? (200, ScenarioFile.data("scenario-observations")) : nil
    }
}

@MainActor @Test func theOutcomeFilterReloads() async throws {
    let fixture = try await observationFixture()
    let model = ObservationsModel.observations(session: fixture.session)
    await model.start()
    #expect(await fixture.eventually { !model.snapshot.rows.isEmpty })
    model.query.outcome = .acted
    #expect(await fixture.eventually { fixture.transport.all.contains("GET observations?outcome=acted&page=1") })
    await model.stop()
    await fixture.session.stop()
}

@Test func aRowWithAHiddenTaskIsNotAButton() throws {
    var object = ScenarioFile.object("scenario-observations")
    var rows = object["observations"] as? [[String: Any]] ?? []
    #expect(!rows.isEmpty)
    rows[0]["taskId"] = NSNull()
    rows[0]["taskUrl"] = NSNull()
    rows[0]["referenceUrl"] = NSNull()
    object["observations"] = rows
    let page = try JSONDecoder().decode(
        ObservationPage.self, from: try JSONSerialization.data(withJSONObject: object))
    #expect(ObservationRowAction.of(page.observations[0]) == .none)
    rows[0]["referenceUrl"] = "https://example.test/issues/1"
    object["observations"] = rows
    let withReference = try JSONDecoder().decode(
        ObservationPage.self, from: try JSONSerialization.data(withJSONObject: object))
    #expect(
        ObservationRowAction.of(withReference.observations[0]) == .web(URL(string: "https://example.test/issues/1")!))
}

@Test func aReferenceThatIsNotHTTPSIsNotAButton() throws {
    var object = ScenarioFile.object("scenario-observations")
    var rows = object["observations"] as? [[String: Any]] ?? []
    rows[0]["taskId"] = NSNull()
    rows[0]["taskUrl"] = NSNull()
    rows[0]["referenceUrl"] = "javascript:alert(1)"
    object["observations"] = rows
    let page = try JSONDecoder().decode(
        ObservationPage.self, from: try JSONSerialization.data(withJSONObject: object))
    #expect(ObservationRowAction.of(page.observations[0]) == .none)
}
