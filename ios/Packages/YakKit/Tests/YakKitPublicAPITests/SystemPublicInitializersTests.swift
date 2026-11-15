import Foundation
import Testing
import YakKit

// Plain `import YakKit`, never `@testable`: the app builds these values with public initializers only.

@Test func systemModelsAreBuildableOutsideTheModule() {
    let instance = SpotlightInstance(id: UUID(), name: "Example Yak")
    #expect(instance.name == "Example Yak")
    let capability = LockCapability(biometry: .none, hasPasscode: true)
    #expect(capability.hasPasscode)
    #expect(AppLockPolicy(isOn: true).isOn)
    var ring = LogRing()
    ring.append(date: Date(timeIntervalSince1970: 0), level: .info, category: "test", message: "hello")
    #expect(ring.lines.count == 1)
    let faults = DebugFaultSwitch()
    #expect(faults.fault == .off)
    _ = DebugFaultTransport(wrapping: URLSessionTransport(), faults: faults, clock: SystemClock())
}
