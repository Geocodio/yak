import Foundation

public enum TaskLink {
    /// The task id from a task's web URL (`<instance>/tasks/<id>`; the instance URL may have its own path and a
    /// trailing slash is allowed). The id is exactly 8 ASCII letters or digits. Anything else gives nil.
    public static func id(from url: URL) -> String? {
        let segments = url.pathComponents.filter { $0 != "/" }
        guard segments.count >= 2, segments[segments.count - 2] == "tasks" else { return nil }
        let id = segments[segments.count - 1]
        guard id.utf8.count == 8, id.utf8.allSatisfy({ $0.isASCIILetterOrDigit }) else { return nil }
        return id
    }
}

extension UInt8 {
    fileprivate var isASCIILetterOrDigit: Bool {
        (0x30...0x39).contains(self) || (0x41...0x5A).contains(self) || (0x61...0x7A).contains(self)
    }
}
