import Foundation
import Testing
import YakKit

@testable import Yak

private let instanceInfo = try! JSONDecoder().decode(
    InstanceInfo.self,
    from: Data(
        #"{"yak":true,"name":"Acme Yak","api":{"current":1,"min":1},"pairing":{"qr":true,"address":true}}"#.utf8))

private let deviceCode = try! JSONDecoder().decode(
    DeviceCode.self,
    from: Data(
        #"{"device_code":"d","user_code":"U","verification_url":"https://yak.example.com/device","verification_url_complete":"https://yak.example.com/device?code=U","expires_in":600,"interval":5}"#
            .utf8))

private let pairedInstance = Instance(
    url: URL(string: "https://yak.example.com")!, name: "Acme Yak", userName: "Ada", lastUsedAt: Date())

private actor FakePairing: PairingServing {
    enum Wait {
        case approve, forever
        case fail(PairingProblem)
    }
    var wait: Wait = .approve
    var checkedAddresses: [URL] = []
    var pairedLinks: [PairingLink] = []
    var failure: PairingProblem?
    var holdsStart = false
    var holdsWait = false

    func hold(start: Bool, wait: Bool) {
        holdsStart = start
        holdsWait = wait
    }

    func setWait(_ wait: Wait) { self.wait = wait }
    func setFailure(_ problem: PairingProblem?) { failure = problem }

    func check(_ address: URL) async throws(PairingProblem) -> InstanceInfo {
        checkedAddresses.append(address)
        if let failure { throw failure }
        return instanceInfo
    }

    func pair(link: PairingLink, device: DeviceDescription) async throws(PairingProblem) -> Instance {
        pairedLinks.append(link)
        if let failure { throw failure }
        return pairedInstance
    }

    func startDeviceFlow(address: URL, device: DeviceDescription) async throws(PairingProblem)
        -> PendingDeviceFlow
    {
        checkedAddresses.append(address)
        while holdsStart { try? await Task.sleep(for: .milliseconds(5)) }
        if let failure { throw failure }
        return PendingDeviceFlow(instance: instanceInfo, url: address, code: deviceCode)
    }

    func waitForApproval(_ flow: PendingDeviceFlow) async throws(PairingProblem) -> Instance {
        while holdsWait { try? await Task.sleep(for: .milliseconds(5)) }
        switch wait {
        case .approve: return pairedInstance
        case .fail(let problem): throw problem
        case .forever:
            do { try await Task.sleep(for: .seconds(3600)) } catch { throw .cancelled }
            return pairedInstance
        }
    }
}

private let device = DeviceDescription(name: "Test", model: "iPhone0,0", osVersion: "27.0", appVersion: "1")

@MainActor private func makeModel(_ fake: FakePairing) -> PairingModel {
    PairingModel(pairing: fake, device: { device })
}

@MainActor @Test func nonsenseAddressIsRefusedWithoutARequest() async {
    let fake = FakePairing()
    let model = makeModel(fake)
    await model.submit(address: "not a host")
    #expect(model.state == .problem(.invalidAddress))
    #expect(await fake.checkedAddresses.isEmpty)
}

@MainActor @Test func aGoodAddressEndsPaired() async {
    let fake = FakePairing()
    let model = makeModel(fake)
    await model.submit(address: "yak.example.com")
    #expect(model.state == .paired(pairedInstance))
    #expect(await fake.checkedAddresses == [URL(string: "https://yak.example.com")!])
    #expect(model.confirmation?.name == "Acme Yak")
}

@MainActor @Test func aGoodAddressMovesThroughCheckingAndWaitingToPaired() async throws {
    let fake = FakePairing()
    await fake.hold(start: true, wait: true)
    let model = makeModel(fake)
    var seen: [PairingModel.State] = []
    let submitting = Task { await model.submit(address: "yak.example.com") }
    try await waitUntil { model.state == .checking }
    seen.append(model.state)
    await fake.hold(start: false, wait: true)
    let approval = URL(string: deviceCode.verificationUrlComplete)!
    try await waitUntil { model.state == .waitingForApproval(url: approval) }
    seen.append(model.state)
    await fake.hold(start: false, wait: false)
    try await waitUntil { model.state == .paired(pairedInstance) }
    seen.append(model.state)
    await submitting.value
    #expect(seen == [.checking, .waitingForApproval(url: approval), .paired(pairedInstance)])
}

@MainActor @Test func aFailedCheckKeepsItsReason() async {
    let fake = FakePairing()
    await fake.setFailure(.notYak)
    let model = makeModel(fake)
    await model.check(address: "yak.example.com")
    #expect(model.checkProblem == .notYak)
    #expect(model.confirmation == nil)
}

@MainActor @Test func reportingAProblemLeavesTheWaitWithThatProblem() async throws {
    let fake = FakePairing()
    await fake.setWait(.forever)
    let model = makeModel(fake)
    let submitting = Task { await model.submit(address: "yak.example.com") }
    try await waitUntil {
        if case .waitingForApproval = model.state { return true }
        return false
    }
    model.report(.other("web_session_failed"))
    await submitting.value
    #expect(model.state == .problem(.other("web_session_failed")))
}

@MainActor @Test func cancellingTheWaitReturnsToIdle() async throws {
    let fake = FakePairing()
    await fake.setWait(.forever)
    let model = makeModel(fake)
    let submitting = Task { await model.submit(address: "yak.example.com") }
    try await waitUntil {
        if case .waitingForApproval = model.state { return true }
        return false
    }
    model.cancel()
    #expect(model.state == .idle)
    await submitting.value
    #expect(model.state == .idle)
}

@MainActor @Test func aProblemIsShownAndResetLeavesIt() async {
    let fake = FakePairing()
    await fake.setFailure(.notYak)
    let model = makeModel(fake)
    await model.submit(address: "yak.example.com")
    #expect(model.state == .problem(.notYak))
    model.reset()
    #expect(model.state == .idle)
}

private let pairingLink = PairingLink(url: URL(string: "yak://pair?url=https%3A%2F%2Fyak.example.com&code=abc")!)!

@MainActor @Test func aLinkAloneWaitsForConfirmationAndRedeemsNothing() async {
    let fake = FakePairing()
    let model = makeModel(fake)
    await model.pair(link: pairingLink)
    #expect(model.state == .confirming(link: pairingLink, instance: instanceInfo, replaces: nil))
    #expect(await fake.pairedLinks.isEmpty)
}

@MainActor @Test func confirmingALinkPairsAndEndsPaired() async {
    let fake = FakePairing()
    let model = makeModel(fake)
    await model.pair(link: pairingLink)
    await model.confirm()
    #expect(model.state == .paired(pairedInstance))
    #expect(await fake.pairedLinks == [pairingLink])
}

@MainActor @Test func cancellingAConfirmationStoresNothingAndSendsNoPairRequest() async {
    let fake = FakePairing()
    let model = makeModel(fake)
    await model.pair(link: pairingLink)
    model.cancel()
    await model.confirm()
    #expect(model.state == .idle)
    #expect(await fake.pairedLinks.isEmpty)
}

@MainActor @Test func aLinkForAnAlreadyPairedAddressReportsWhatItReplaces() async {
    let model = PairingModel(
        pairing: FakePairing(), device: { device }, pairedInstances: { [pairedInstance] })
    await model.pair(link: pairingLink)
    #expect(model.state == .confirming(link: pairingLink, instance: instanceInfo, replaces: pairedInstance))
}

@MainActor @Test func aLinkToSomethingThatIsNotYakEndsInNotYak() async {
    let fake = FakePairing()
    await fake.setFailure(.notYak)
    let model = makeModel(fake)
    await model.pair(link: pairingLink)
    #expect(model.state == .problem(.notYak))
}

@Test(arguments: PairingProblem.allKeyNames)
func everyProblemHasItsScreenText(name: String) {
    let bundle = Bundle(for: PairingModel.self)
    for key in ["problem.\(name).title", "problem.\(name).body"] {
        #expect(bundle.localizedString(forKey: key, value: "__missing__", table: nil) != "__missing__", "\(key)")
    }
}

@Test func eachProblemMapsToItsOwnKeys() {
    let problems: [PairingProblem] = [
        .invalidAddress, .notYak, .unreachable, .serverTooOld, .appTooOld, .codeInvalid, .codeExpired, .denied,
    ]
    #expect(Set(problems.map(\.titleKey)).count == problems.count)
    #expect(PairingProblem.other("x").titleKey == "problem.other.title")
}

@MainActor private func waitUntil(_ condition: @MainActor () -> Bool) async throws {
    for _ in 0..<200 {
        if condition() { return }
        try await Task.sleep(for: .milliseconds(10))
    }
    Issue.record("condition never became true")
}
