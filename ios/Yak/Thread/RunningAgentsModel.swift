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

    init(threadID: String, engine: @escaping @MainActor () -> ThreadEngine?) {
        self.threadID = threadID
        self.engine = engine
    }

    func showReported(_ agents: RunningAgents?) { bar.showReported(agents) }

    /// The page's report for the page load it comes from; a report about another thread, or none, hands the numbers
    /// back to the thread show.
    func pageReported(_ report: AgentsReport?) {
        if let report, report.threadID == threadID {
            bar.pageReported(running: report.running, newest: report.newest)
        } else if bar.hasPageReported {
            bar.pageReset()
        }
    }

    func askToStop() {
        guard bar.canRequestStop else { return }
        isConfirming = true
    }

    /// Sends "Stop all" once; a tap while the request runs, or while the bar says "Stopping", sends nothing.
    func stop() async {
        guard bar.beginStop() else { return }
        let result = await engine()?.stopAgents() ?? AgentsStopResult(outcome: .failed(.offline))
        bar.finishStop(result)
        guard bar.phase == .stopping else { return }
        stoppingTask?.cancel()
        stoppingTask = Task { [weak self] in
            do { try await Task.sleep(for: Self.stoppingLimit) } catch { return }
            self?.bar.stoppingTimedOut()
        }
    }
}
