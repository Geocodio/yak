import Foundation

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

    /// The first value after the last one `next` returned that matches.
    func next(where predicate: @Sendable (Value) -> Bool) async -> Value {
        while true {
            if let index = values[cursor...].firstIndex(where: predicate) {
                cursor = index + 1
                return values[index]
            }
            await withCheckedContinuation { waiters.append($0) }
        }
    }
}
