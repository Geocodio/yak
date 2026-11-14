import Foundation
import Observation
import YakKit

/// What `PairingModel` needs from the pairing logic; `Pairing` conforms, tests fake it.
protocol PairingServing: Sendable {
    func check(_ address: URL) async throws(PairingProblem) -> InstanceInfo
    func pair(link: PairingLink, device: DeviceDescription, requestID: String?) async throws(PairingProblem)
        -> Instance
    func startDeviceFlow(address: URL, device: DeviceDescription) async throws(PairingProblem)
        -> PendingDeviceFlow
    func waitForApproval(_ flow: PendingDeviceFlow) async throws(PairingProblem) -> Instance
}

extension Pairing: PairingServing {}

extension PairingProblem {
    /// String Catalog keys for the screen text of this problem.
    var titleKey: String { "problem.\(keyName).title" }
    var bodyKey: String { "problem.\(keyName).body" }

    private var keyName: String {
        switch self {
        case .invalidAddress: "invalidAddress"
        case .notYak: "notYak"
        case .unreachable: "unreachable"
        case .serverTooOld: "serverTooOld"
        case .appTooOld: "appTooOld"
        case .codeInvalid: "codeInvalid"
        case .codeExpired: "codeExpired"
        case .codeMayBeUsed: "codeMayBeUsed"
        case .denied: "denied"
        case .other("web_session_failed"): "signInPage"
        case .cancelled, .other: "other"
        }
    }

    /// Every key name a screen can ask for, so a test can check the catalog.
    static let allKeyNames = [
        "invalidAddress", "notYak", "unreachable", "serverTooOld", "appTooOld", "codeInvalid",
        "codeExpired", "denied", "other", "signInPage", "codeMayBeUsed", "approvalExpired",
    ]
}

@MainActor @Observable
final class PairingModel {
    enum State: Equatable {
        case idle
        case checking
        case waitingForApproval(url: URL)
        /// A pairing link was checked and waits for the person to confirm; nothing is stored yet.
        case confirming(link: PairingLink, instance: InstanceInfo, replaces: Instance?)
        /// The code of the confirmed link is being redeemed. The confirmation stays on screen, busy.
        case redeeming(link: PairingLink, instance: InstanceInfo, replaces: Instance?)
        case problem(PairingProblem)
        case paired(Instance)
    }

    private(set) var state: State = .idle
    /// What the typed address said about itself, once `check` succeeded.
    private(set) var confirmation: InstanceInfo?
    /// Why the last `check` found no Yak, shown as a note under the field.
    private(set) var checkProblem: PairingProblem?

    private let pairing: any PairingServing
    private let normalizeAddress: @Sendable (String) -> URL?
    private let device: @MainActor () -> DeviceDescription
    private let onPaired: @MainActor (Instance) async -> Void
    private let pairedInstances: @MainActor () async -> [Instance]
    private var task: Task<Void, Never>?
    private var generation = 0
    /// Names one redeem of one link, so a repeat of it reaches the instance as the same request.
    private var requestID = UUID().uuidString

    init(
        pairing: any PairingServing,
        device: @escaping @MainActor () -> DeviceDescription,
        onPaired: @escaping @MainActor (Instance) async -> Void = { _ in },
        pairedInstances: @escaping @MainActor () async -> [Instance] = { [] },
        normalizeAddress: @escaping @Sendable (String) -> URL? = InstanceAddress.normalize
    ) {
        self.normalizeAddress = normalizeAddress
        self.pairedInstances = pairedInstances
        self.pairing = pairing
        self.device = device
        self.onPaired = onPaired
    }

    /// Asks the typed address what it is, to show the confirmation row, or the reason it is not a Yak.
    func check(address: String) async {
        confirmation = nil
        checkProblem = nil
        guard let url = normalizeAddress(address) else { return }
        do throws(PairingProblem) {
            let info = try await pairing.check(url)
            guard !Task.isCancelled else { return }
            confirmation = info
        } catch {
            guard !Task.isCancelled, error != .cancelled else { return }
            checkProblem = error
        }
    }

    /// Shows a problem that did not come from the pairing logic, such as the sign-in sheet failing to open.
    func report(_ problem: PairingProblem) {
        generation += 1
        task?.cancel()
        task = nil
        state = .problem(problem)
    }

    /// Checks the address, starts the device flow and waits for the approval.
    func submit(address: String) async {
        guard let url = normalizeAddress(address) else {
            state = .problem(.invalidAddress)
            return
        }
        await run { (model) throws(PairingProblem) in
            model.state = .checking
            let flow = try await model.pairing.startDeviceFlow(address: url, device: model.device())
            guard let approval = URL(string: flow.code.verificationUrlComplete) else {
                throw PairingProblem.other("bad_verification_url")
            }
            model.confirmation = flow.instance
            model.state = .waitingForApproval(url: approval)
            return try await model.pairing.waitForApproval(flow)
        }
    }

    /// Checks the instance a link names, then waits in `.confirming`. Nothing is redeemed or stored
    /// until `confirm()`.
    func pair(link: PairingLink) async {
        task?.cancel()
        generation += 1
        let mine = generation
        state = .checking
        let running = Task { @MainActor [weak self] in
            guard let self else { return }
            let outcome: Result<InstanceInfo, PairingProblem>
            do throws(PairingProblem) { outcome = .success(try await pairing.check(link.instance)) } catch {
                outcome = .failure(error)
            }
            let paired = await pairedInstances()
            guard mine == generation else { return }
            switch outcome {
            case .success(let info):
                requestID = UUID().uuidString
                state = .confirming(
                    link: link, instance: info, replaces: paired.first { $0.url == link.instance })
            case .failure(.cancelled): state = .idle
            case .failure(let problem): state = .problem(problem)
            }
        }
        task = running
        await running.value
    }

    /// Redeems the code of the link being confirmed. It sends no instance check: that happened
    /// before the confirmation.
    func confirm() async {
        guard case .confirming(let link, let info, let replaces) = state else { return }
        state = .redeeming(link: link, instance: info, replaces: replaces)
        let requestID = requestID
        await run { (model) throws(PairingProblem) in
            try await model.pairing.pair(link: link, device: model.device(), requestID: requestID)
        }
    }

    /// Gives up waiting for a redeem. The instance may have accepted the code already, so the
    /// person is told to start over with a new one instead of checking the instance again.
    func abandonRedeem() {
        guard case .redeeming = state else { return }
        generation += 1
        task?.cancel()
        task = nil
        state = .problem(.codeMayBeUsed)
    }

    /// Stops a check or a wait and returns to the start.
    func cancel() {
        generation += 1
        task?.cancel()
        task = nil
        state = .idle
    }

    /// Leaves a problem screen for the form.
    func reset() {
        if case .problem = state { state = .idle }
    }

    private func run(
        _ work: @escaping @MainActor (PairingModel) async throws(PairingProblem) -> Instance
    ) async {
        task?.cancel()
        generation += 1
        let mine = generation
        let running = Task { @MainActor [weak self] in
            guard let self else { return }
            let outcome: Result<Instance, PairingProblem>
            do throws(PairingProblem) { outcome = .success(try await work(self)) } catch { outcome = .failure(error) }
            guard mine == generation else { return }
            switch outcome {
            case .success(let instance):
                state = .paired(instance)
                await onPaired(instance)
            case .failure(.cancelled): state = .idle
            case .failure(let problem): state = .problem(problem)
            }
        }
        task = running
        await running.value
    }
}
