import Foundation
import Testing

@testable import YakKit

#if canImport(FoundationNetworking)
    import FoundationNetworking
#endif

private func makeTransport(_ fault: DebugFault) -> (DebugFaultTransport, FakeTransport, ManualClock, DebugFaultSwitch) {
    let inner = FakeTransport(.json(200, #"{"ok":true}"#))
    let clock = ManualClock()
    let faults = DebugFaultSwitch()
    faults.set(fault)
    return (DebugFaultTransport(wrapping: inner, faults: faults, clock: clock), inner, clock, faults)
}

private let sampleRequest = URLRequest(url: URL(string: "https://example.test/api/app/v1/me")!)

@Suite struct DebugFaultsTests {
    @Test func aNewSwitchStartsOff() {
        #expect(DebugFaultSwitch().fault == .off)
    }

    @Test func offPassesEveryRequestThrough() async throws {
        let (transport, inner, _, _) = makeTransport(.off)
        let (data, response) = try await transport.send(sampleRequest)
        #expect(response.statusCode == 200 && String(decoding: data, as: UTF8.self) == #"{"ok":true}"#)
        #expect(await inner.requests == [sampleRequest])
    }

    @Test(arguments: [(DebugFault.slow, 4), (DebugFault.verySlow, 10)])
    func slowFaultsWaitThenSend(fault: DebugFault, seconds: Int) async throws {
        let (transport, inner, clock, _) = makeTransport(fault)
        let task = Task { try await transport.send(sampleRequest) }
        await clock.waitForSleepers(1)
        await clock.advance(by: .seconds(seconds) - .milliseconds(100))
        #expect(await inner.requests.isEmpty)
        await clock.advance(by: .milliseconds(100))
        _ = try await task.value
        #expect(await inner.requests.count == 1)
    }

    @Test func cancellingAWaitSendsNothing() async throws {
        let (transport, inner, clock, _) = makeTransport(.slow)
        let task = Task { try await transport.send(sampleRequest) }
        await clock.waitForSleepers(1)
        task.cancel()
        await #expect(throws: CancellationError.self) { try await task.value }
        #expect(await inner.requests.isEmpty)
    }

    @Test func offlineAndServerErrorSendNothing() async throws {
        let (offline, offlineInner, _, _) = makeTransport(.offline)
        await #expect(throws: URLError.self) { try await offline.send(sampleRequest) }
        #expect(await offlineInner.requests.isEmpty)

        let (broken, brokenInner, _, _) = makeTransport(.serverError)
        let (data, response) = try await broken.send(sampleRequest)
        #expect(response.statusCode == 503)
        #expect(APIError.parse(status: 503, body: data)?.code == "unavailable")
        #expect(await brokenInner.requests.isEmpty)
    }

    @Test func theSwitchIsReadOncePerRequest() async throws {
        let (transport, inner, clock, faults) = makeTransport(.slow)
        let task = Task { try await transport.send(sampleRequest) }
        await clock.waitForSleepers(1)
        faults.set(.offline)
        await clock.advance(by: .seconds(4))
        _ = try await task.value
        #expect(await inner.requests.count == 1)
    }

    @Test func onlyDebugAndTestFlightBuildsGetTheMenu() {
        let rows: [(Bool, String?, DebugBuildKind)] = [
            (true, nil, .debug), (true, "Production", .debug), (false, "Xcode", .debug),
            (false, "Sandbox", .testFlight), (false, "Production", .appStore),
            (false, "Other", .unknown), (false, nil, .unknown),
        ]
        for (isDebug, environment, expected) in rows {
            #expect(DebugMenuAvailability.kind(isDebugBuild: isDebug, storeEnvironment: environment) == expected)
        }
        #expect(DebugMenuAvailability.isAvailable(.debug) && DebugMenuAvailability.isAvailable(.testFlight))
        #expect(!DebugMenuAvailability.isAvailable(.appStore) && !DebugMenuAvailability.isAvailable(.unknown))
    }
}
