import Foundation

/// A text frame the terminal bridge sends.
public enum BridgeMessage: Equatable, Sendable {
    case exit(code: Int)
    case unknown

    /// Reads one control frame. Anything that is not a well-formed `exit` frame is `.unknown`.
    public static func parse(_ text: String) -> BridgeMessage {
        guard let frame = try? JSONDecoder().decode(ExitFrame.self, from: Data(text.utf8)),
            frame.type == "exit",
            frame.code.magnitude <= 1_000_000
        else { return .unknown }
        return .exit(code: frame.code)
    }

    /// Decoding `code` as an `Int` rejects booleans, fractions and out-of-range numbers on every platform.
    private struct ExitFrame: Decodable {
        let type: String
        let code: Int
    }
}

/// A text frame the app sends. The app never sends the bridge's `ping`, so its idle close keeps working.
public enum BridgeControl {
    /// `{"type":"resize","cols":N,"rows":M}` with both clamped to the bridge's range of 1 to 500.
    public static func resize(cols: Int, rows: Int) -> String {
        let columns = min(max(cols, 1), 500)
        let lines = min(max(rows, 1), 500)
        return #"{"type":"resize","cols":\#(columns),"rows":\#(lines)}"#
    }
}

public enum TerminalCloseKind: Equatable, Sendable {
    case ended, lost, denied, idle, tooMany, broken

    /// After an exit frame the shell ended whatever the code. 1000 ended. 1001, 1006, 1011 and no code are
    /// a lost connection. 4403 is a denied token, 4408 the idle close, 4429 too many shells. Every other
    /// code is a broken connection.
    public static func classify(code: Int?, sawExit: Bool) -> TerminalCloseKind {
        if sawExit { return .ended }
        switch code {
        case 1000: return .ended
        case 1001, 1006, 1011, nil: return .lost
        case 4403: return .denied
        case 4408: return .idle
        case 4429: return .tooMany
        default: return .broken
        }
    }
}
