#if canImport(Darwin)
    import Foundation
import Synchronization
    import Testing

    @testable import YakStub

    @Suite struct FakeBridgeTests {
        private static let origin = "https://stub.example.test"

        private func frames() throws -> Data {
            var directory = URL(fileURLWithPath: #filePath)
            while directory.lastPathComponent != "Packages" { directory.deleteLastPathComponent() }
            return try Data(
                contentsOf: directory.appendingPathComponent(
                    "YakKit/Tests/YakKitTests/Scenarios/scenario-terminal-frames.json"))
        }

        private func bridge(_ admission: BridgeAdmission = .open) async throws -> (FakeBridge, Int) {
            let bridge = FakeBridge(frames: try frames(), origin: Self.origin, admit: { _ in admission })
            return (bridge, try await bridge.start())
        }

        private func socket(
            _ port: Int, origin: String? = origin, query: String = "token=a&cols=80&rows=24"
        ) -> URLSessionWebSocketTask {
            var request = URLRequest(url: URL(string: "ws://127.0.0.1:\(port)/terminal/ws?\(query)")!)
            if let origin { request.setValue(origin, forHTTPHeaderField: "Origin") }
            request.timeoutInterval = 5
            let task = URLSession.shared.webSocketTask(with: request)
            task.resume()
            return task
        }

        private func text(_ task: URLSessionWebSocketTask) async throws -> String {
            switch try await task.receive() {
            case .data(let data): return String(decoding: data, as: UTF8.self)
            case .string(let string): return string
            @unknown default: return ""
            }
        }

        /// Reads until `needle` has arrived.
        private func read(_ task: URLSessionWebSocketTask, until needle: String) async throws -> String {
            var seen = ""
            while !seen.contains(needle) { seen += try await text(task) }
            return seen
        }

        private func closeCode(_ task: URLSessionWebSocketTask) async -> Int {
            while true {
                do { _ = try await task.receive() } catch { return task.closeCode.rawValue }
            }
        }

        @Test(.timeLimit(.minutes(1))) func refusesAWrongOriginOrARefusedToken() async throws {
            let (bridge, port) = try await bridge()
            defer { bridge.stop() }
            #expect(await closeCode(socket(port, origin: "https://other.example.test")) == 4403)
            let (refusing, refusingPort) = try await self.bridge(.refuse)
            defer { refusing.stop() }
            #expect(await closeCode(socket(refusingPort)) == 4403)
        }

        @Test(.timeLimit(.minutes(1))) func aWrongOriginDoesNotUseUpAToken() async throws {
            let asked = Mutex(0)
            let bridge = FakeBridge(
                frames: try frames(), origin: Self.origin,
                admit: { _ in
                    asked.withLock { $0 += 1 }
                    return .open
                })
            let port = try await bridge.start()
            defer { bridge.stop() }
            #expect(await closeCode(socket(port, origin: "https://other.example.test")) == 4403)
            #expect(asked.withLock { $0 } == 0)
        }

        @Test(.timeLimit(.minutes(1))) func sendsTheBannerAndEchoes() async throws {
            let (bridge, port) = try await bridge()
            defer { bridge.stop() }
            let task = socket(port)
            #expect(try await read(task, until: "stub$ ").contains("Example sandbox"))
            try await task.send(.data(Data("echo hello\r".utf8)))
            let output = try await read(task, until: "stub$ ")
            #expect(output.contains("echo hello\r\n"))
            #expect(output.contains("hello\r\n"))
            task.cancel(with: .goingAway, reason: nil)
        }

        @Test(.timeLimit(.minutes(1))) func exitSendsTheExitFrame() async throws {
            let (bridge, port) = try await bridge()
            defer { bridge.stop() }
            let task = socket(port)
            _ = try await read(task, until: "stub$ ")
            try await task.send(.data(Data("exit\r".utf8)))
            #expect(try await read(task, until: #"{"type":"exit","code":0}"#).contains("exit"))
            #expect(await closeCode(task) == 1000)
        }

        @Test(.timeLimit(.minutes(1))) func recordsTheResize() async throws {
            let (bridge, port) = try await bridge()
            defer { bridge.stop() }
            let task = socket(port, query: "token=a&cols=100&rows=30")
            _ = try await read(task, until: "stub$ ")
            #expect(bridge.lastResize?.columns == 100)
            #expect(bridge.lastResize?.rows == 30)
            try await task.send(.string(#"{"type":"resize","cols":120,"rows":40}"#))
            try await task.send(.data(Data("x".utf8)))
            _ = try await read(task, until: "x")
            #expect(bridge.lastResize?.columns == 120)
            #expect(bridge.lastResize?.rows == 40)
            task.cancel(with: .goingAway, reason: nil)
        }

        @Test(.timeLimit(.minutes(1))) func closeAllClosesWithTheCode() async throws {
            let (bridge, port) = try await bridge()
            defer { bridge.stop() }
            let task = socket(port)
            _ = try await read(task, until: "stub$ ")
            bridge.closeAll(code: 4408)
            #expect(await closeCode(task) == 4408)
        }

        @Test(.timeLimit(.minutes(1))) func tooManyClosesAtOnce() async throws {
            let (bridge, port) = try await bridge(.tooMany)
            defer { bridge.stop() }
            #expect(await closeCode(socket(port)) == 4429)
        }

        @Test(.timeLimit(.minutes(1))) func countsOpenedSockets() async throws {
            let (bridge, port) = try await bridge()
            defer { bridge.stop() }
            #expect(bridge.openedCount == 0)
            let task = socket(port)
            _ = try await read(task, until: "stub$ ")
            #expect(bridge.openedCount == 1)
            bridge.resetCounts()
            #expect(bridge.openedCount == 0)
            task.cancel(with: .goingAway, reason: nil)
        }
    }
#endif
