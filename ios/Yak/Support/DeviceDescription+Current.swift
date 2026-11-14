import UIKit
import YakKit

extension DeviceDescription {
    /// This device as the instance lists it: the name, the hardware model string from `uname`,
    /// the OS version and the app's bundle version.
    @MainActor static func current() -> DeviceDescription {
        DeviceDescription(
            name: UIDevice.current.name,
            model: hardwareModel(),
            osVersion: UIDevice.current.systemVersion,
            appVersion: appVersion())
    }

    static func hardwareModel() -> String {
        var system = utsname()
        uname(&system)
        return withUnsafeBytes(of: &system.machine) { buffer in
            let bytes = buffer.prefix { $0 != 0 }
            return String(decoding: bytes, as: UTF8.self)
        }
    }

    static func appVersion() -> String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "0"
    }

    /// "iPhone" or "iPad", for copy that names the device.
    @MainActor static var kindName: String { UIDevice.current.model }
}
