import Foundation
import Testing

@testable import YakKit

private let threadID = "d0e515ef"
private let openRoute = "POST threads/\(threadID)/pull-request"

private func makeActions(_ transport: ScriptedTransport) -> PullRequestActions {
    let client = APIClient(
        baseURL: URL(string: "https://example.test")!, token: "test-token", transport: transport,
        appVersion: "1.0", systemDescription: "Test", sleep: { _ in })
    return PullRequestActions(threadID: threadID, client: client)
}

private func sentBody(_ request: URLRequest) -> String {
    String(decoding: request.httpBody ?? Data(), as: UTF8.self)
}

@Test func aDoubleTapSendsOnce() async throws {
    let transport = ScriptedTransport()
    await transport.on(openRoute, try .scenario(202, "scenario-pull-request-opening"))
    await transport.hold(openRoute)
    let actions = makeActions(transport)
    async let first = actions.open(acknowledgedPrivatePublish: false)
    await transport.waitForRequests(1, on: openRoute)
    async let second = actions.open(acknowledgedPrivatePublish: false)
    while await actions.joinedCallCount < 1 { await Task.yield() }
    await transport.release(openRoute)
    #expect(await first == .started(.opening))
    #expect(await second == .started(.opening))
    #expect(await transport.requests(on: openRoute).count == 1)
}

@Test func everyAnswerMaps() async throws {
    let transport = ScriptedTransport()
    let actions = makeActions(transport)

    await transport.on(openRoute, try .scenario(202, "scenario-pull-request-opening"))
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .started(.opening))
    await transport.on(openRoute, try .scenario(200, "scenario-pull-request-open"))
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .started(.open))
    await transport.on(openRoute, try .scenario(200, "scenario-pull-request-held"))
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .started(.held))

    await transport.on(
        openRoute,
        .json(
            422,
            #"{"error":"validation_failed","message":"Confirm.","details":{"errors":{"acknowledged_private_publish":["Confirm that this private thread may publish."]}}}"#
        ))
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .needsConfirmation)

    await transport.on(
        openRoute, .json(409, #"{"error":"nothing_to_publish","message":"There is nothing to publish."}"#))
    #expect(
        await actions.open(acknowledgedPrivatePublish: true)
            == .refused(code: "nothing_to_publish", message: "There is nothing to publish."))
    await transport.on(openRoute, .json(403, #"{"error":"forbidden","message":"No."}"#))
    #expect(await actions.open(acknowledgedPrivatePublish: true) == .refused(code: "forbidden", message: "No."))
    await transport.on(openRoute, .json(404, #"{"error":"not_found","message":"Gone."}"#))
    #expect(await actions.open(acknowledgedPrivatePublish: true) == .refused(code: "not_found", message: "Gone."))
    await transport.on(openRoute, .json(500, #"{"error":"server_error","message":"Broke."}"#))
    #expect(
        await actions.open(acknowledgedPrivatePublish: true)
            == .failed(.server(code: "server_error", message: "Broke.")))
}

@Test func theAcknowledgementIsSentOnlyWhenGiven() async throws {
    let transport = ScriptedTransport()
    await transport.on(openRoute, try .scenario(202, "scenario-pull-request-opening"))
    let actions = makeActions(transport)
    _ = await actions.open(acknowledgedPrivatePublish: false)
    _ = await actions.open(acknowledgedPrivatePublish: true)
    let requests = await transport.requests(on: openRoute)
    #expect(requests.count == 2)
    #expect(sentBody(requests[0]) == "{}")
    #expect(sentBody(requests[1]).contains(#""acknowledged_private_publish":true"#))
}

@Test func aTimeoutFailsOnceAndNeverResends() async throws {
    let transport = ScriptedTransport()
    await transport.on(openRoute, .failure(.timedOut))
    let actions = makeActions(transport)
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .failed(.offline))
    #expect(await transport.requests(on: openRoute).count == 1)
}

@Test func aFinishedRequestDoesNotBlockTheNext() async throws {
    let transport = ScriptedTransport()
    await transport.on(
        openRoute, .failure(.notConnectedToInternet), try .scenario(202, "scenario-pull-request-opening"))
    let actions = makeActions(transport)
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .failed(.offline))
    #expect(await actions.open(acknowledgedPrivatePublish: false) == .started(.opening))
    #expect(await transport.requests(on: openRoute).count == 2)
}

@Test func askToFixAndReorderMapTheirRefusals() async throws {
    let transport = ScriptedTransport()
    let actions = makeActions(transport)
    let fixRoute = "POST threads/\(threadID)/pull-requests/78/fix"
    await transport.on(fixRoute, .json(409, #"{"error":"ci_not_failing","message":"CI is not failing."}"#))
    #expect(
        await actions.askToFix(rowID: 78)
            == .refused(code: "ci_not_failing", message: "CI is not failing.", fieldErrors: [:]))
    await transport.on(fixRoute, .json(200, #"{"status":"sent"}"#))
    #expect(await actions.askToFix(rowID: 78) == .done)

    let reorderRoute = "PUT threads/\(threadID)/pull-requests/order"
    await transport.on(reorderRoute, .json(200, #"{"status":"updated"}"#))
    #expect(await actions.reorder([78, 77]) == .done)
    #expect(sentBody(try #require(await transport.requests(on: reorderRoute).first)).contains("[78,77]"))
    await transport.on(reorderRoute, .failure(.timedOut))
    #expect(await actions.reorder([77, 78]) == .failed(.offline))
}

@Test func aDoubleTapOnAskToFixSendsOnce() async throws {
    let transport = ScriptedTransport()
    let route = "POST threads/\(threadID)/pull-requests/78/fix"
    await transport.on(route, .json(200, #"{"status":"sent"}"#))
    await transport.hold(route)
    let actions = makeActions(transport)
    async let first = actions.askToFix(rowID: 78)
    await transport.waitForRequests(1, on: route)
    async let second = actions.askToFix(rowID: 78)
    while await actions.joinedCallCount < 1 { await Task.yield() }
    await transport.release(route)
    #expect(await first == .done)
    #expect(await second == .done)
    #expect(await transport.requests(on: route).count == 1)
}

@Test func reorderRefusesAnEmptyOrOversizedListWithoutARequest() async throws {
    let transport = ScriptedTransport()
    let actions = makeActions(transport)
    let route = "PUT threads/\(threadID)/pull-requests/order"
    for order in [[], Array(1...51)] {
        guard case .refused(let code, _, _) = await actions.reorder(order) else {
            Issue.record("expected a refusal for \(order.count) ids")
            continue
        }
        #expect(code == "validation_failed")
    }
    #expect(await transport.requests(on: route).isEmpty)
}
