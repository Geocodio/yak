import Foundation
import Testing

@testable import YakKit

let refusingAuthorizer: ChannelAuthorizer = { _, _ throws(APIFailure) -> ChannelAuthorization in throw .notYak }

@Suite struct PusherConnectionTests {
    private func makeClient(
        server: FakeWebSocketServer, clock: ManualClock, scheme: String = "https"
    ) -> RealtimeClient {
        RealtimeClient(
            configuration: RealtimeConfiguration(
                Me.Realtime(host: "ws.example.test", key: "reverb-key", port: 443, scheme: scheme),
                appVersion: "1.0.0"),
            connector: server, authorizer: refusingAuthorizer, clock: clock)
    }

    @Test func connectsWithTheConfiguredAddress() async throws {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        let connection = await server.waitForConnection(0)
        #expect(
            await server.connectedURLs[0].absoluteString
                == "wss://ws.example.test:443/app/reverb-key?protocol=7&client=yak-app&version=1.0.0&flash=false")
        await connection.serverSends(Frames.established(socketID: "123.456", activityTimeout: 30))
        _ = await states.next { $0 == .live }
        #expect(await realtime.socketID() == "123.456")
        await realtime.stop()
    }

    @Test func backsOffOneTwoFourEightFifteenThenFifteen() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await server.refuseNext(7)
        await realtime.start()
        var elapsed = Duration.zero
        for step in [1, 2, 4, 8, 15, 15, 15] as [Int64] {
            elapsed += .seconds(step)
            await clock.waitForOnlySleeper(endingAt: elapsed)
            #expect(await states.values.last == .reconnecting)
            await clock.advance(by: .seconds(step))
        }
        let connection = await server.waitForConnection(0)
        #expect(await server.connectedURLs.count == 8)
        await connection.serverSends(Frames.established(socketID: "1.2", activityTimeout: 30))
        _ = await states.next { $0 == .live }
        await realtime.stop()
    }

    @Test func answersThePingAndPingsWhenQuiet() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.2", activityTimeout: 120))
        _ = await states.next { $0 == .live }
        await connection.serverSends(Frames.ping)
        await connection.waitForSent(1)
        #expect(await connection.sent.last == #"{"event":"pusher:pong","data":{}}"#)
        // The pong's frame restarted the quiet timer at 30 seconds (120 is capped to 30).
        await clock.waitForSleeper(endingAt: .seconds(30))
        await clock.advance(by: .seconds(30))
        await connection.waitForSent(2)
        #expect(await connection.sent.last == #"{"event":"pusher:ping","data":{}}"#)
        await clock.waitForSleeper(endingAt: .seconds(40))
        await clock.advance(by: .seconds(10))
        await connection.waitUntilClosed()
        _ = await states.next { $0 == .reconnecting }
        await clock.waitForSleeper(endingAt: .seconds(41))
        await clock.advance(by: .seconds(1))
        _ = await server.waitForConnection(1)
        await realtime.stop()
    }

    @Test func aMissingHandshakeCountsAsAFailure() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await clock.waitForSleeper(endingAt: .seconds(15))
        await clock.advance(by: .seconds(15))
        await connection.waitUntilClosed()
        _ = await states.next { $0 == .reconnecting }
        await clock.waitForSleeper(endingAt: .seconds(16))
        await clock.advance(by: .seconds(1))
        _ = await server.waitForConnection(1)
        await realtime.stop()
    }

    @Test func aPermanentErrorStopsReconnecting() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.error(code: 4001))
        _ = await states.next { $0 == .reconnecting }
        _ = await states.next { $0 == .offline }
        await connection.waitUntilClosed()
        await clock.advance(by: .seconds(60))
        #expect(clock.sleeperDeadlines.isEmpty)
        #expect(await server.connectedURLs.count == 1)
        // start() works again afterwards.
        await realtime.start()
        _ = await server.waitForConnection(1)
        await realtime.stop()
    }

    @Test func errorFrom4200ReconnectsAtOnce() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.error(code: 4200))
        _ = await server.waitForConnection(1)
        await realtime.stop()
    }

    @Test func networkReturnSkipsTheWait() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        await server.refuseNext(4)
        await realtime.start()
        for step in [1, 2, 4] as [Int64] {
            await clock.waitForOnlySleeper(endingAt: .seconds(step == 1 ? 1 : step == 2 ? 3 : 7))
            await clock.advance(by: .seconds(step))
        }
        await clock.waitForOnlySleeper(endingAt: .seconds(15))
        await server.refuseNext(1)
        await realtime.networkPathChanged(isSatisfied: true)
        // Connects at once (refused), and the next wait is 1 second again.
        await clock.waitForOnlySleeper(endingAt: .seconds(8))
        #expect(await server.connectedURLs.count == 5)
        await realtime.stop()
    }

    @Test func noNetworkMeansOffline() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await server.refuseNext(1)
        await realtime.start()
        await clock.waitForSleeper(endingAt: .seconds(1))
        await realtime.networkPathChanged(isSatisfied: false)
        _ = await states.next { $0 == .offline }
        await clock.advance(by: .seconds(60))
        #expect(await server.connectedURLs.count == 1)
        await realtime.networkPathChanged(isSatisfied: true)
        _ = await server.waitForConnection(0)
        await realtime.stop()
    }

    @Test func aNetworkChangeWhileStoppedIsRemembered() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        await realtime.start()
        _ = await server.waitForConnection(0)
        await realtime.networkPathChanged(isSatisfied: false)
        await realtime.stop()
        await realtime.networkPathChanged(isSatisfied: true)
        await realtime.start()
        _ = await server.waitForConnection(1)
        await realtime.stop()
    }

    @Test func aStartDuringAStopKeepsItsOwnHandshakeTimer() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        await realtime.start()
        let old = await server.waitForConnection(0)
        await old.holdClose()
        let stopping = Task { await realtime.stop() }
        await old.waitUntilClosed()
        await realtime.start()
        let fresh = await server.waitForConnection(1)
        await clock.waitForOnlySleeper(endingAt: .seconds(15))
        await old.releaseClose()
        await stopping.value
        await clock.advance(by: .seconds(15))
        await fresh.waitUntilClosed()
        await realtime.stop()
    }

    @Test func networkReturningMidAttemptShowsReconnecting() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        _ = await server.waitForConnection(0)
        await realtime.networkPathChanged(isSatisfied: false)
        _ = await states.next { $0 == .offline }
        await realtime.networkPathChanged(isSatisfied: true)
        _ = await states.next { $0 == .reconnecting }
        await realtime.stop()
    }

    @Test func sameStateIsNeverBroadcastTwiceInARow() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.2", activityTimeout: 30))
        _ = await states.next { $0 == .live }
        await realtime.networkPathChanged(isSatisfied: true)
        await connection.drop()
        _ = await states.next { $0 == .reconnecting }
        await realtime.stop()
        _ = await states.next { $0 == .offline }
        let recorded = await states.values
        #expect(recorded == [.offline, .reconnecting, .live, .reconnecting, .offline])
    }

    @Test func noConfigurationNeverConnects() async {
        let server = FakeWebSocketServer()
        let realtime = RealtimeClient(
            configuration: RealtimeConfiguration(nil, appVersion: "1.0.0"), connector: server,
            authorizer: refusingAuthorizer, clock: ManualClock())
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        _ = await states.next { $0 == .offline }
        #expect(await server.connectedURLs.isEmpty)
        await realtime.stop()
    }

    @Test func stopClosesTheSocketAndDoesNotReconnect() async {
        let server = FakeWebSocketServer()
        let clock = ManualClock()
        let realtime = makeClient(server: server, clock: clock)
        let states = StreamRecorder(await realtime.states())
        await realtime.start()
        let connection = await server.waitForConnection(0)
        await connection.serverSends(Frames.established(socketID: "1.2", activityTimeout: 30))
        _ = await states.next { $0 == .live }
        await realtime.stop()
        #expect(await connection.isClosed)
        #expect(await realtime.socketID() == nil)
        await clock.advance(by: .seconds(60))
        #expect(await server.connectedURLs.count == 1)
        _ = await states.next { $0 == .offline }
    }

    @Test func refusesAPlainSocketUnlessAllowed() {
        let plain = Me.Realtime(host: "ws.example.test", key: "k", port: 80, scheme: "http")
        #expect(RealtimeConfiguration(plain, appVersion: "1.0.0") == nil)
        #expect(
            RealtimeConfiguration(plain, appVersion: "1.0.0", allowsInsecure: true)?.url.absoluteString.hasPrefix(
                "ws://") == true)
    }

    @Test func decodesFramesWithStringOrObjectData() {
        #expect(PusherFrame.decode(#"{"event":"a","data":"{}"}"#)?.data == "{}")
        #expect(PusherFrame.decode(#"{"event":"a","data":{}}"#)?.data == "{}")
        #expect(PusherFrame.decode(#"{"event":"a","channel":"c"}"#)?.channel == "c")
        #expect(PusherFrame.decode("garbage") == nil)
        let frame = PusherFrame(event: "pusher:subscribe", channel: nil, data: #"{"auth":"x"}"#)
        #expect(frame.encoded() == #"{"event":"pusher:subscribe","data":{"auth":"x"}}"#)
    }
}
