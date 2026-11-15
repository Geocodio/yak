import Foundation

/// The four notification switches: the saved values, the saves in flight and the last failed save.
public struct NotificationSwitches: Equatable, Sendable {
    public enum Load: Equatable, Sendable {
        case loading, loaded
        case failed(PushProblem)
    }

    public struct SaveProblem: Equatable, Sendable {
        public let event: PushEvent
        public let problem: PushProblem

        public init(event: PushEvent, problem: PushProblem) {
            self.event = event
            self.problem = problem
        }
    }

    public private(set) var load: Load = .loading
    public private(set) var problem: SaveProblem?
    private var preferences: NotificationPreferences
    private var pending: [PushEvent: Bool] = [:]

    /// A saved copy shows at once while the load runs; without one every switch reads as on.
    public init(saved: NotificationPreferences?) {
        preferences = saved ?? NotificationPreferences(events: [:])
    }

    /// A save in flight shows its new value.
    public func isOn(_ event: PushEvent) -> Bool {
        pending[event] ?? preferences.isOn(event)
    }

    public func isSaving(_ event: PushEvent) -> Bool {
        pending[event] != nil
    }

    public mutating func loaded(_ answer: NotificationPreferences) {
        merge(answer)
        load = .loaded
    }

    public mutating func loadFailed(_ problem: PushProblem) {
        load = .failed(problem)
    }

    /// Returns the request to send, or nil when this switch is already saving or the value is unchanged.
    public mutating func toggle(_ event: PushEvent, to isOn: Bool) -> (event: PushEvent, isOn: Bool)? {
        guard !isSaving(event), self.isOn(event) != isOn else { return nil }
        pending[event] = isOn
        if problem?.event == event { problem = nil }
        return (event, isOn)
    }

    public mutating func saved(_ answer: NotificationPreferences, for event: PushEvent) {
        pending[event] = nil
        merge(answer)
        if problem?.event == event { problem = nil }
    }

    /// The switch returns to its saved value and a line says why.
    public mutating func saveFailed(_ event: PushEvent, problem: PushProblem) {
        pending[event] = nil
        self.problem = SaveProblem(event: event, problem: problem)
    }

    /// Takes the server's answer for every switch that is not saving.
    private mutating func merge(_ answer: NotificationPreferences) {
        var events = preferences.events
        for (key, value) in answer.events where pending[PushEvent(rawValue: key)] == nil {
            events[key] = value
        }
        preferences = NotificationPreferences(events: events)
    }
}
