import Foundation
import Testing

@testable import YakKit

private actor AuthCallRecorder {
    private(set) var values: [AuthCall] = []
    func append(_ socketID: String, _ channel: String) { values.append(AuthCall(socketID: socketID, channel: channel)) }
}

private struct AuthCall: Equatable, Sendable {
    let socketID: String
    let channel: String
}

private let threadID = "d0e515ef"
private let otherThreadID = "aa11bb22"

private func makeRealtime(
    server: FakeWebSocketServer, clock: ManualClock, authorizer: @escaping ChannelAuthorizer
) -> RealtimeClient {
    RealtimeClient(
        configuration: RealtimeConfiguration(
            Me.Realtime(host: "ws.example.test", key: "reverb-key", port: 443, scheme: "https"),
            appVersion: "1.0.0"),
        connector: server, authorizer: authorizer, clock: clock)
}

private func recordingAuthorizer(_ calls: AuthCallRecorder) -> ChannelAuthorizer {
    { socket, channel throws(APIFailure) in
        await calls.append(socket, channel)
        return ChannelAuthorization(
            auth: "reverb-key:\(socket)", channelData: channel.hasPrefix("presence-") ? #"{"user_id":7}"# : nil)
    }
}

private func confirmBoth(_ connection: FakeConnection, thread: String = threadID) async {
    await connection.serverSends(Frames.subscribed("private-thread.\(thread)"))
    await connection.serverSends(Frames.subscribed("presence-thread-presence.\(thread)"))
}

private func eventJSON(sequence: Int) throws -> String {
    let scenario = try JSONSerialization.jsonObject(with: Scenarios.data("events/simple-turn")) as? [String: Any]
    let broadcasts = try #require(scenario?["broadcasts"] as? [[String: Any]])
    let event = try #require(broadcasts.first { ($0["sequence"] as? Int) == sequence })
    return String(decoding: try JSONSerialization.data(withJSONObject: event), as: UTF8.self)
}

@Suite struct PusherChannelTests {
    @Test func aReconnectAuthorizesAgainWithTheNewSocketID() async throws {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let calls = AuthCallRecorder()
        let realtime = makeRealtime(server: server, clock: clock, authorizer: recordingAuthorizer(calls))
        let messages = StreamRecorder(await realtime.subscribe(thread: threadID))
        await realtime.start()
        let first = await server.waitForConnection(0)
        await first.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await first.waitForSent(2)
        await confirmBoth(first)
        _ = await messages.next { $0 == .subscribed }
        await first.drop()
        await clock.waitForSleeper(endingAt: .seconds(1))
        await clock.advance(by: .seconds(1))
        let second = await server.waitForConnection(1)
        await second.serverSends(Frames.established(socketID: "2.2", activityTimeout: 30))
        await second.waitForSent(2)
        #expect(
            await calls.values == [
                AuthCall(socketID: "1.1", channel: "private-thread.\(threadID)"),
                AuthCall(socketID: "1.1", channel: "presence-thread-presence.\(threadID)"),
                AuthCall(socketID: "2.2", channel: "private-thread.\(threadID)"),
                AuthCall(socketID: "2.2", channel: "presence-thread-presence.\(threadID)"),
            ])
        #expect(await second.sent.allSatisfy { !$0.contains("reverb-key:1.1") })
        await confirmBoth(second)
        _ = await messages.next { $0 == .subscribed }
        #expect(await messages.values.filter { $0 == .subscribed }.count == 2)
        await realtime.stop()
    }

    @Test func sendsThePresenceChannelData() async throws {
        let server = FakeWebSocketServer()
        let realtime = makeRealtime(
            server: server, clock: ManualClock(), authorizer: recordingAuthorizer(AuthCallRecorder()))
        _ = await realtime.subscribe(thread: threadID)
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await connection.waitForSent(2)
        let sent = await connection.sent
        #expect(
            sent[0]
                == #"{"event":"pusher:subscribe","data":{"auth":"reverb-key:1.1","channel":"private-thread.\#(threadID)"}}"#
        )
        let presence = try #require(
            sent[1].data(using: .utf8).flatMap { try? JSONSerialization.jsonObject(with: $0) }
                as? [String: Any])
        let data = try #require(presence["data"] as? [String: String])
        #expect(data["channel_data"] == #"{"user_id":7}"#)
        #expect(data["channel"] == "presence-thread-presence.\(threadID)")
        await realtime.stop()
    }

    @Test func deliversEventsAndPointers() async throws {
        let server = FakeWebSocketServer()
        let realtime = makeRealtime(
            server: server, clock: ManualClock(), authorizer: recordingAuthorizer(AuthCallRecorder()))
        let messages = StreamRecorder(await realtime.subscribe(thread: threadID))
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await connection.waitForSent(2)
        await connection.serverSends(
            Frames.threadEvent(channel: "private-thread.\(otherThreadID)", data: try eventJSON(sequence: 8)))
        await connection.serverSends(Frames.threadEvent(channel: "private-thread.\(threadID)", data: "not json"))
        await connection.serverSends(
            Frames.threadEvent(channel: "private-thread.\(threadID)", data: try eventJSON(sequence: 8)))
        await connection.serverSends(
            Frames.threadEvent(channel: "private-thread.\(threadID)", data: #"{"sequence":9,"pointer":true}"#))
        let event = await messages.next {
            if case .event = $0 { return true } else { return false }
        }
        guard case .event(let delivered) = event else { return }
        #expect(delivered.sequence == 8)
        _ = await messages.next { $0 == .pointer(sequence: 9) }
        #expect(await messages.values.filter { if case .event = $0 { true } else { false } }.count == 1)
        await realtime.stop()
    }

    @Test func aRefusedAuthorizationFailsOnlyThatThread() async throws {
        let server = FakeWebSocketServer()
        let forbidden = APIFailure.server(
            APIError(status: 403, code: "forbidden", message: "Test refusal", details: [:]))
        let realtime = makeRealtime(
            server: server, clock: ManualClock(),
            authorizer: { _, channel throws(APIFailure) in
                if channel.hasSuffix(otherThreadID) { throw forbidden }
                return ChannelAuthorization(auth: "k:1", channelData: nil)
            })
        let refused = StreamRecorder(await realtime.subscribe(thread: otherThreadID))
        let allowed = StreamRecorder(await realtime.subscribe(thread: threadID))
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        _ = await refused.next { $0 == .subscriptionFailed(forbidden) }
        await connection.waitForSent(2)
        await confirmBoth(connection)
        _ = await allowed.next { $0 == .subscribed }
        #expect(await refused.values.contains(.subscribed) == false)
        await realtime.stop()
    }

    @Test func unauthorizedIsPassedOn() async {
        let server = FakeWebSocketServer()
        let revoked = APIFailure.unauthorized(code: "device_revoked")
        let realtime = makeRealtime(
            server: server, clock: ManualClock(), authorizer: { _, _ throws(APIFailure) in throw revoked })
        let messages = StreamRecorder(await realtime.subscribe(thread: threadID))
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        _ = await messages.next { $0 == .subscriptionFailed(revoked) }
        await realtime.stop()
    }

    @Test func aServerSubscriptionErrorGivesNil() async {
        let server = FakeWebSocketServer()
        let realtime = makeRealtime(
            server: server, clock: ManualClock(), authorizer: recordingAuthorizer(AuthCallRecorder()))
        let messages = StreamRecorder(await realtime.subscribe(thread: threadID))
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await connection.serverSends(
            #"{"event":"pusher:subscription_error","channel":"private-thread.\#(threadID)","data":"{}"}"#)
        _ = await messages.next { $0 == .subscriptionFailed(nil) }
        await realtime.stop()
    }

    @Test func unsubscribeLeavesBothChannelsAndEndsTheStream() async {
        let server = FakeWebSocketServer()
        let realtime = makeRealtime(
            server: server, clock: ManualClock(), authorizer: recordingAuthorizer(AuthCallRecorder()))
        let stream = await realtime.subscribe(thread: threadID)
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await connection.waitForSent(2)
        await realtime.unsubscribe(thread: threadID)
        await connection.waitForSent(4)
        let sent = await connection.sent
        #expect(sent[2] == #"{"event":"pusher:unsubscribe","data":{"channel":"private-thread.\#(threadID)"}}"#)
        #expect(
            sent[3] == #"{"event":"pusher:unsubscribe","data":{"channel":"presence-thread-presence.\#(threadID)"}}"#)
        var count = 0
        for await _ in stream { count += 1 }  // returns only because the stream ended
        #expect(count >= 1)
        await realtime.stop()
    }

    @Test func aSecondSubscribeEndsTheFirstStream() async {
        let realtime = makeRealtime(
            server: FakeWebSocketServer(), clock: ManualClock(), authorizer: recordingAuthorizer(AuthCallRecorder()))
        let first = await realtime.subscribe(thread: threadID)
        _ = await realtime.subscribe(thread: threadID)
        for await _ in first {}
        await realtime.stop()
    }

    @Test func suspendAndResume() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeRealtime(server: server, clock: clock, authorizer: recordingAuthorizer(AuthCallRecorder()))
        let messages = StreamRecorder(await realtime.subscribe(thread: threadID))
        await realtime.start()
        let first = await server.waitForConnection(0)
        await first.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await first.waitForSent(2)
        await confirmBoth(first)
        _ = await messages.next { $0 == .subscribed }
        await realtime.suspend()
        #expect(await first.isClosed)
        #expect(clock.sleeperDeadlines.isEmpty)
        let sent = await first.sent
        #expect(sent.suffix(2).allSatisfy { $0.contains("pusher:unsubscribe") })
        _ = await messages.next { $0 == .connection(.offline) }
        #expect(await server.acceptedCount == 1)
        await realtime.resume()
        let second = await server.waitForConnection(1)
        await second.serverSends(Frames.established(socketID: "2.2", activityTimeout: 30))
        await second.waitForSent(2)
        await confirmBoth(second)
        _ = await messages.next { $0 == .subscribed }
        await realtime.stop()
    }

    @Test func startAfterSuspendAndStopConnects() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeRealtime(server: server, clock: clock, authorizer: recordingAuthorizer(AuthCallRecorder()))
        _ = await realtime.subscribe(thread: threadID)
        await realtime.start()
        let first = await server.waitForConnection(0)
        await first.serverSends(Frames.established(socketID: "1.1", activityTimeout: 30))
        await realtime.suspend()
        await realtime.stop()
        await realtime.start()
        _ = await server.waitForConnection(1)
        await realtime.stop()
    }

    @Test func streamsStartWithTheConnectionState() async {
        let realtime = makeRealtime(
            server: FakeWebSocketServer(), clock: ManualClock(), authorizer: recordingAuthorizer(AuthCallRecorder()))
        let messages = StreamRecorder(await realtime.subscribe(thread: threadID))
        #expect(await messages.next { _ in true } == .connection(.offline))
        await realtime.stop()
    }
}
