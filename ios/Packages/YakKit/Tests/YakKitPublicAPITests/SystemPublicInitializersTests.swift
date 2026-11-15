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
    let entry = SpotlightEntry(
        identifier: "id", domain: "domain", title: "Title", instanceName: "Example Yak",
        expiresAt: Date(timeIntervalSince1970: 0))
    #expect(entry.title == "Title")
    let record = SpotlightIndexRecord(
        indexedDomains: [instance.id], pendingErasures: [], pendingEraseAll: false, includedPrivate: true)
    #expect(record.includedPrivate)
    #expect(SpotlightIndexRecord().indexedDomains.isEmpty)
    #expect(
        LogLine(date: Date(timeIntervalSince1970: 0), level: .info, category: "test", text: "hello").text == "hello")
    let faults = DebugFaultSwitch()
    #expect(faults.fault == .off)
    _ = DebugFaultTransport(wrapping: URLSessionTransport(), faults: faults, clock: SystemClock())
}
