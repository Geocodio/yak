import Foundation
import Observation
import YakKit

/// What the Debug menu shows and does: the request faults and the build kind that allows the menu.
@MainActor @Observable
final class DebugMenuModel {
    @ObservationIgnored private let faults: DebugFaultSwitch
    @ObservationIgnored private let buildKind: any BuildKindSource

    /// Nil until the build kind is known (at most 5 seconds).
    private(set) var kind: DebugBuildKind?
    private(set) var fault: DebugFault

    var isAvailable: Bool { kind.map(DebugMenuAvailability.isAvailable) ?? false }

    init(faults: DebugFaultSwitch, buildKind: any BuildKindSource) {
        self.faults = faults
        self.buildKind = buildKind
        fault = faults.fault
    }

    func start() async {
        guard kind == nil else { return }
        kind = await buildKind.kind()
    }

    func setFault(_ next: DebugFault) {
        faults.set(next)
        fault = next
    }
}
