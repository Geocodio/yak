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
    /// Before the person's removal reaches the server: the bearer token still exists, so a part can tell the
    /// server what it registered. Not called for a revoke, where the server already forgot this phone.
    func instanceWillBeRemoved(_ instance: Instance) async
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async
    /// After `me` was refreshed and stored for the instance.
    func instanceRefreshed(_ instance: Instance) async
}

extension InstanceLifecycleObserver {
    func instancePaired(_ instance: Instance) async {}
    func instanceWillBeRemoved(_ instance: Instance) async {}
    func instanceRefreshed(_ instance: Instance) async {}
    func instanceRemoved(_ instance: Instance, reason: InstanceRemoval) async {}
}

/// Tells each observer in turn, and gives up on one that takes longer than `timeout`. The slow call is left to
/// finish by itself; its result is not waited for, and a log line names its type.
@MainActor
struct InstanceObservers {
    let observers: [any InstanceLifecycleObserver]
    let timeout: Duration
    /// Waits out `timeout`; replaced in tests to see that the timer is cancelled once the call has finished.
    var sleep: @Sendable (Duration) async throws -> Void = { try await Task.sleep(for: $0) }

    func paired(_ instance: Instance) async {
        for observer in observers { await within(observer) { await observer.instancePaired(instance) } }
    }

    /// Gives each observer `removalTimeout` to tell the server before the removal goes on.
    func willRemove(_ instance: Instance, removalTimeout: Duration) async {
        let patient = InstanceObservers(observers: observers, timeout: removalTimeout, sleep: sleep)
        for observer in observers { await patient.within(observer) { await observer.instanceWillBeRemoved(instance) } }
    }

    func refreshed(_ instance: Instance) async {
        for observer in observers { await within(observer) { await observer.instanceRefreshed(instance) } }
    }

    func removed(_ instance: Instance, reason: InstanceRemoval) async {
        for observer in observers {
            await within(observer) { await observer.instanceRemoved(instance, reason: reason) }
        }
    }

    func within(
        _ observer: any InstanceLifecycleObserver, _ call: @escaping @MainActor () async -> Void
    ) async {
        let gate = Gate()
        await withCheckedContinuation { (continuation: CheckedContinuation<Void, Never>) in
            let timer = Task { @MainActor [timeout, sleep] in
                do { try await sleep(timeout) } catch { return }
                if gate.finish(continuation) {
                    #if canImport(os)
                        Logger(subsystem: "Yak", category: "Lifecycle")
                            .error(
                                "An observer took too long: \(String(describing: type(of: observer)), privacy: .public)"
                            )
                    #endif
                }
            }
            Task { @MainActor in
                await call()
                gate.finish(continuation)
                timer.cancel()
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
