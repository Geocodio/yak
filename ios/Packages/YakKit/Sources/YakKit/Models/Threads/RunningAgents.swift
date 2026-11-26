import Foundation

/// One agent of the thread that has not finished. `state` is `running`, or `stopping` once a stop was asked for.
public struct RunningAgent: Codable, Equatable, Sendable, Identifiable {
    public let id: String
    public let name: String
    public let state: String
    public let startedAt: String?

    public init(id: String, name: String, state: String = "running", startedAt: String? = nil) {
        self.id = id
        self.name = name
        self.state = state
        self.startedAt = startedAt
    }

    public var isStopping: Bool { state == "stopping" }
}

/// The agents of a thread that run now, as the thread show and the stop answer carry them. `running` lists at most
/// the first twenty, so `runningCount` is the number to show. A show from an older server has none.
public struct RunningAgents: Codable, Equatable, Sendable {
    public let runningCount: Int
    public let running: [RunningAgent]

    public init(runningCount: Int, running: [RunningAgent] = []) {
        self.runningCount = runningCount
        self.running = running
    }

    private enum CodingKeys: String, CodingKey { case runningCount, running }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        running =
            ((try? container.decodeIfPresent([Lossy<RunningAgent>].self, forKey: .running)) ?? nil)?
            .compactMap(\.value) ?? []
        runningCount = max(0, try container.decodeIfPresent(Int.self, forKey: .runningCount) ?? running.count)
    }

    /// Every listed agent has been asked to stop.
    public var isStopping: Bool { !running.isEmpty && running.allSatisfy(\.isStopping) }
}

/// The answer to "Stop all": `idle` when nothing ran, `stopping` otherwise, and the agents that still run.
public struct AgentsStopAnswer: Decodable, Equatable, Sendable {
    public let status: String
    public let stopped: Int
    public let agents: RunningAgents

    private enum CodingKeys: String, CodingKey { case status, stopped, agents }

    public init(from decoder: any Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        status = try container.decode(String.self, forKey: .status)
        stopped = try container.decodeIfPresent(Int.self, forKey: .stopped) ?? 0
        agents = try container.decode(RunningAgents.self, forKey: .agents)
    }
}

/// How a "Stop all" ended: the outcome, and on success the agents the server says remain.
public struct AgentsStopResult: Equatable, Sendable {
    public let outcome: ThreadActionOutcome
    public let agents: RunningAgents?

    public init(outcome: ThreadActionOutcome, agents: RunningAgents? = nil) {
        self.outcome = outcome
        self.agents = agents
    }
}
