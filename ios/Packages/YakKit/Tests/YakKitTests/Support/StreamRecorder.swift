import Foundation
import Testing

/// Collects what a stream yields, so a test can wait for a value instead of sleeping.
actor StreamRecorder<Value: Sendable> {
    private(set) var values: [Value] = []
    private var cursor = 0
    private var waiters: [CheckedContinuation<Void, Never>] = []

    init(_ stream: AsyncStream<Value>) {
        Task { [weak self] in
            for await value in stream {
                guard let self else { return }
                await self.append(value)
            }
        }
    }

    private func append(_ value: Value) {
        values.append(value)
        let ready = waiters
        waiters = []
        for waiter in ready { waiter.resume() }
    }

    private func wake() {
        let ready = waiters
        waiters = []
        for waiter in ready { waiter.resume() }
    }

    /// The first value after the last one `next` returned that matches. A value that does not arrive
    /// within ten real seconds fails the test and stops the run, instead of hanging it.
    func next(where predicate: @Sendable (Value) -> Bool) async -> Value {
        let deadline = ContinuousClock.now + .seconds(10)
        while true {
            if let index = values[cursor...].firstIndex(where: predicate) {
                cursor = index + 1
                return values[index]
            }
            if ContinuousClock.now > deadline {
                Issue.record("Timed out waiting for a matching value; received \(values)")
                fatalError("StreamRecorder.next timed out")
            }
            let timeout = Task { [weak self] in
                try? await Task.sleep(for: .seconds(1))
                await self?.wake()
            }
            await withCheckedContinuation { waiters.append($0) }
            timeout.cancel()
        }
    }
}
