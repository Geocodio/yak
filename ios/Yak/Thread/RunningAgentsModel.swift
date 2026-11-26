import Foundation
import Observation
import YakKit

/// The state of the agents bar of one thread, and the "Stop all" request behind it.
@MainActor @Observable
final class RunningAgentsModel {
    /// How long the bar says "Stopping" before it offers "Stop all" again, when no report has said the agents are gone.
    static let stoppingLimit: Duration = .seconds(45)

    private(set) var bar = RunningAgentsBar()
    /// The person tapped "Stop all" and is being asked to confirm.
    var isConfirming = false

    @ObservationIgnored private let threadID: String
    @ObservationIgnored private let engine: @MainActor () -> ThreadEngine?
    @ObservationIgnored private var stoppingTask: Task<Void, Never>?
    @ObservationIgnored private var armedStoppingSerial = 0

    init(threadID: String, engine: @escaping @MainActor () -> ThreadEngine?) {
        self.threadID = threadID
        self.engine = engine
    }

    func showReported(_ agents: RunningAgents?) {
        bar.showReported(agents)
        barChanged()
    }

    func connectivityChanged(isOffline: Bool) {
        bar.connectivityChanged(isOffline: isOffline)
        barChanged()
    }

    /// The question goes when there is nothing left to ask about, and the "Stopping" timeout starts on every entry
    /// into that phase, whoever reported it.
    private func barChanged() {
        if bar.count == 0 { isConfirming = false }
        guard bar.phase == .stopping else {
            stoppingTask?.cancel()
            stoppingTask = nil
            return
        }
        guard bar.stoppingSerial != armedStoppingSerial else { return }
        armedStoppingSerial = bar.stoppingSerial
        stoppingTask?.cancel()
        stoppingTask = Task { [weak self] in
            do { try await Task.sleep(for: Self.stoppingLimit) } catch { return }
            self?.bar.stoppingTimedOut()
            self?.barChanged()
        }
    }

    /// The page's report for the page load it comes from; a report about another thread, or none, hands the numbers
    /// back to the thread show.
    func pageReported(_ report: AgentsReport?) {
        if let report, report.threadID == threadID {
            bar.pageReported(running: report.running, newest: report.newest)
        } else if bar.hasPageReported {
            bar.pageReset()
        }
        barChanged()
    }

    func askToStop() {
        guard bar.canRequestStop else { return }
        isConfirming = true
    }

    /// Sends "Stop all" once; a tap while the request runs, or while the bar says "Stopping", sends nothing.
    func stop() async {
        guard bar.beginStop() else { return }
        barChanged()
        let result = await engine()?.stopAgents() ?? AgentsStopResult(outcome: .failed(.offline))
        bar.finishStop(result)
        barChanged()
    }
}
