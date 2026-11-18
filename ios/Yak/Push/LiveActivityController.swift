import Foundation
import YakKit

#if canImport(os)
    import os
#endif

/// Starts a Live Activity for a thread whose turn runs while the person has it open, keeps at most three, ends
/// duplicates and reports the running set to each instance. It holds no thread text beyond the start values.
@MainActor
final class LiveActivityController {
    private struct ThreadKey: Hashable {
        let instance: UUID
        let thread: String
    }

    private let host: any ActivityHost
    private let registrar: PushRegistrar
    private let instances: InstanceStore
    private var wasActive: [ThreadKey: Bool] = [:]
    private var starting: Set<ThreadKey> = []
    /// Instances whose server said it cannot push; tried again after the next `me` refresh.
    private var stoppedInstances: Set<UUID> = []
    private var isReconciling = false
    private var needsAnotherReconcile = false
    private var changesTask: Task<Void, Never>?
    private var tokensTask: Task<Void, Never>?

    init(host: any ActivityHost, registrar: PushRegistrar, instances: InstanceStore) {
        self.host = host
        self.registrar = registrar
        self.instances = instances
    }

    /// Follows the activities and the push-to-start tokens, and reports the running set once.
    func start() async {
        if changesTask == nil {
            let changes = host.changes()
            changesTask = Task { [weak self] in
                for await _ in changes { await self?.reconcile() }
            }
        }
        if tokensTask == nil {
            let tokens = host.pushToStartTokens()
            tokensTask = Task { [registrar] in
                for await token in tokens {
                    await registrar.setToken(PushRegistrar.hex(token), kind: .liveActivityStart)
                }
            }
        }
        await reconcile()
    }

    /// An instance's availability may have changed, so a stop for it ends.
    func instanceRefreshed(_ instance: Instance) {
        stoppedInstances.remove(instance.id)
    }

    /// Runs on every snapshot of an open thread. It starts on the change from not running to running, and on the
    /// first snapshot of a thread whose turn already runs.
    func observe(threadID: String, instance: Instance, snapshot: ConversationSnapshot) async {
        let key = ThreadKey(instance: instance.id, thread: threadID)
        let isActive = snapshot.turn?.state.isActive == true
        let previous = wasActive[key]
        wasActive[key] = isActive
        guard isActive, previous != true, !starting.contains(key), !stoppedInstances.contains(instance.id),
            let show = snapshot.show,
            LiveActivityPlanner.shouldStart(
                show: show, turnIsActive: true, availability: instance.push,
                activitiesEnabled: host.areActivitiesEnabled())
        else { return }
        starting.insert(key)
        defer { starting.remove(key) }

        let channel: String
        if let known = show.liveActivityChannel {
            channel = known
        } else {
            do throws(PushProblem) {
                channel = try await registrar.liveActivityChannel(thread: threadID, instance: instance.id)
            } catch {
                if error == .pushUnavailable { stoppedInstances.insert(instance.id) }
                log("The channel was refused: \(String(describing: error).prefix { $0 != "(" })")
                return
            }
        }

        let paired = await instances.all()
        let eligible = paired.filter { $0.push == .available }
        let running = await runningActivities(among: paired)
        let plan = LiveActivityPlanner.plan(
            starting: threadID, on: instance.url, running: running, instances: eligible.map(\.url))
        for activityID in plan.endActivityIDs { await host.end(activityID) }
        if plan.starts {
            let values = LiveActivityPlanner.startValues(show: show, instance: instance.url, now: Date())
            do { _ = try await host.start(values, channel: channel) } catch {
                log("The activity did not start: \(String(describing: type(of: error)))")
                return
            }
        }
        await report(plan.reports, among: eligible)
    }

    /// Ends every activity of `instance`, whatever address its attributes name: its paired address or the address
    /// the server reports. The instance may already have left the store, so it is matched in addition to the
    /// paired list. The server drops its rows when the device is removed, so nothing is reported.
    func endAll(instance: Instance) async {
        let paired = await instances.all()
        for activity in await runningActivities(among: paired + [instance]) where activity.instance == instance.url {
            await host.end(activity.activityID)
        }
        wasActive = wasActive.filter { $0.key.instance != instance.id }
    }

    // MARK: Helpers

    /// Ends duplicates and the excess over three, then reports every instance's set.
    private func reconcile() async {
        if isReconciling {
            needsAnotherReconcile = true
            return
        }
        isReconciling = true
        defer { isReconciling = false }
        repeat {
            needsAnotherReconcile = false
            let paired = await instances.all()
            let eligible = paired.filter { $0.push == .available }
            let running = await runningActivities(among: paired)
            let plan = LiveActivityPlanner.reconcile(running: running, instances: eligible.map(\.url))
            for activityID in plan.endActivityIDs { await host.end(activityID) }
            await report(plan.reports, among: eligible)
        } while needsAnotherReconcile
    }

    /// The host's activities with each address replaced by the paired instance's own, so an activity started by a
    /// push (the server's address) and one the app started count for the same instance.
    private func runningActivities(among paired: [Instance]) async -> [RunningActivity] {
        await host.running().map { activity in
            let match = InstanceMatch.instance(for: activity.instance, in: paired)
            return RunningActivity(
                activityID: activity.activityID, instance: match?.url ?? activity.instance,
                threadID: activity.threadID, startedAt: activity.startedAt)
        }
    }

    private func report(_ reports: [URL: [String]], among eligible: [Instance]) async {
        for (url, threads) in reports {
            guard let instance = eligible.first(where: { $0.url == url }) else { continue }
            await registrar.reportActivities(threads, instance: instance.id)
        }
    }

    private func log(_ message: String) {
        #if canImport(os)
            Logger(subsystem: "Yak", category: "LiveActivity").error("\(message, privacy: .public)")
        #endif
    }
}
