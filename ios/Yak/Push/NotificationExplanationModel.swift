import Foundation
import Observation
import YakKit

/// What the explanation screen asks of the person after a pairing.
struct NotificationExplanationRequest: Identifiable, Equatable {
    let id: UUID
    let instanceName: String
}

extension PushController {
    /// After a pairing: shows the explanation, registers silently, or does nothing.
    func didPair(_ instance: Instance) async {
        let current = await instances.all().first { $0.id == instance.id } ?? instance
        await readSystemState()
        switch NotificationOnboarding.afterPairing(availability: current.push, authorization: authorization) {
        case .explain: explanation = NotificationExplanationRequest(id: UUID(), instanceName: current.name)
        case .register: await registerIfAllowed()
        case .skip: await registrar.sync()
        }
    }
}

@MainActor @Observable
final class NotificationExplanationModel {
    enum Phase: Equatable {
        /// The system prompt is up.
        case asking
        case idle
        case on
        case off(failed: Bool)
    }

    /// How long "Notifications are on" shows before the screen closes by itself.
    nonisolated(unsafe) static var closingDelay: Duration = .seconds(1)

    let request: NotificationExplanationRequest
    private(set) var phase: Phase = .idle

    @ObservationIgnored private let push: PushController
    @ObservationIgnored private let isOnline: @MainActor () -> Bool
    @ObservationIgnored private let clock: any YakClock
    @ObservationIgnored private var closingTask: Task<Void, Never>?

    init(
        request: NotificationExplanationRequest, push: PushController, isOnline: @escaping @MainActor () -> Bool,
        clock: any YakClock
    ) {
        self.request = request
        self.push = push
        self.isOnline = isOnline
        self.clock = clock
    }

    var isOffline: Bool { !isOnline() }

    /// The only path to the system prompt on this screen. A second tap while the prompt is up does nothing.
    func turnOn() async {
        guard phase == .idle else { return }
        phase = .asking
        switch await push.requestPermission() {
        case .granted:
            phase = .on
            closingTask = Task { [clock] in
                do { try await clock.sleep(for: Self.closingDelay) } catch { return }
                self.finish()
            }
        case .denied: phase = .off(failed: false)
        case .failed: phase = .off(failed: true)
        }
    }

    /// Closes at once, asks nothing and stores nothing.
    /// While the system prompt is up the button stays as it is and does nothing, so it is not dimmed.
    func notNow() {
        guard phase != .asking else { return }
        finish()
    }

    func finish() {
        closingTask?.cancel()
        push.explanation = nil
    }
}
