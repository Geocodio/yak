import Foundation

/// A key the key row or the hardware keyboard sends to the shell.
public enum TerminalKey: Equatable, Sendable {
    case escape, tab, up, down, left, right
    case character(Character)
}

public enum TerminalKeys {
    /// The bytes a key sends. `control` applies to letters, `@ [ \ ] ^ _ ? space` and arrows;
    /// `applicationCursor` is the terminal's DECCKM mode, read from the emulator.
    public static func bytes(for key: TerminalKey, control: Bool, applicationCursor: Bool) -> [UInt8] {
        switch key {
        case .escape:
            return [0x1B]
        case .tab:
            return [0x09]
        case .up, .down, .left, .right:
            let final = arrowFinalByte(key)
            if control {
                return [0x1B, 0x5B, 0x31, 0x3B, 0x35, final]
            }
            return [0x1B, applicationCursor ? 0x4F : 0x5B, final]
        case .character(let character):
            if control, let byte = controlByte(for: character) {
                return [byte]
            }
            return Array(String(character).utf8)
        }
    }

    private static func arrowFinalByte(_ key: TerminalKey) -> UInt8 {
        switch key {
        case .up: return 0x41
        case .down: return 0x42
        case .right: return 0x43
        default: return 0x44
        }
    }

    private static func controlByte(for character: Character) -> UInt8? {
        guard let scalar = character.unicodeScalars.first, character.unicodeScalars.count == 1,
            scalar.isASCII
        else { return nil }
        let value = UInt8(scalar.value)
        switch value {
        case 0x61...0x7A: return value - 0x60
        case 0x41...0x5A: return value - 0x40
        case 0x40, 0x20: return 0
        case 0x5B: return 27
        case 0x5C: return 28
        case 0x5D: return 29
        case 0x5E: return 30
        case 0x5F: return 31
        case 0x3F: return 127
        default: return nil
        }
    }
}

/// The key row's Control key: it applies to the next key only.
public struct ControlLatch: Equatable, Sendable {
    public private(set) var isOn: Bool

    public init() {
        isOn = false
    }

    public mutating func toggle() {
        isOn.toggle()
    }

    /// True once after `toggle()` turned it on; it is then off again.
    public mutating func consume() -> Bool {
        let wasOn = isOn
        isOn = false
        return wasOn
    }
}
