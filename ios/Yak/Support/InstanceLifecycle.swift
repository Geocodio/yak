import YakKit

#if canImport(os)
    import os
#endif

enum InstanceRemoval: Sendable { case unpaired, revoked }

/// A part of the app that follows the instances this phone is paired with (push registration, erasing web data,
/// widget timelines). It is told after the route has changed, so it never holds the screen.
@MainActor
protocol InstanceLifecycleObserver: AnyObject {
    func instancePaired(_ instance: Instance) async
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async
}

extension InstanceLifecycleObserver {
    func instancePaired(_ instance: Instance) async {}
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {}
}

/// Tells each observer in turn, and gives up on one that takes longer than `timeout`. The slow call is left to
/// finish by itself; its result is not waited for, and a log line names its type.
@MainActor
struct InstanceObservers {
    let observers: [any InstanceLifecycleObserver]
    let timeout: Duration

    func paired(_ instance: Instance) async {
        for observer in observers { await within(observer) { await observer.instancePaired(instance) } }
    }

    func removed(_ instance: Instance, reason: InstanceRemoval) async {
        for observer in observers {
            await within(observer) { await observer.instanceRemoved(instance, reason: reason) }
        }
    }

    private func within(
        _ observer: any InstanceLifecycleObserver, _ call: @escaping @MainActor () async -> Void
    ) async {
        let gate = Gate()
        await withCheckedContinuation { (continuation: CheckedContinuation<Void, Never>) in
            Task { @MainActor in
                await call()
                gate.finish(continuation)
            }
            Task { @MainActor [timeout] in
                try? await Task.sleep(for: timeout)
                if gate.finish(continuation) {
                    #if canImport(os)
                        Logger(subsystem: "Yak", category: "Lifecycle")
                            .error(
                                "An observer took too long: \(String(describing: type(of: observer)), privacy: .public)"
                            )
                    #endif
                }
            }
        }
    }

    /// Lets the first of the call and the timer resume the waiting caller.
    @MainActor private final class Gate {
        private var isFinished = false
        @discardableResult func finish(_ continuation: CheckedContinuation<Void, Never>) -> Bool {
            guard !isFinished else { return false }
            isFinished = true
            continuation.resume()
            return true
        }
    }
}
