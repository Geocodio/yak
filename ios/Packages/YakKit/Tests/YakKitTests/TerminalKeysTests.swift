import Testing

@testable import YakKit

struct TerminalKeysTests {
    @Test func arrowsFollowTheCursorMode() {
        #expect(TerminalKeys.bytes(for: .up, control: false, applicationCursor: false) == [0x1B, 0x5B, 0x41])
        #expect(TerminalKeys.bytes(for: .up, control: false, applicationCursor: true) == [0x1B, 0x4F, 0x41])
        #expect(TerminalKeys.bytes(for: .right, control: false, applicationCursor: false) == [0x1B, 0x5B, 0x43])
        for mode in [false, true] {
            #expect(
                TerminalKeys.bytes(for: .left, control: true, applicationCursor: mode)
                    == [0x1B, 0x5B, 0x31, 0x3B, 0x35, 0x44])
        }
    }

    @Test func controlLettersAreOneToTwentySix() {
        #expect(TerminalKeys.bytes(for: .character("c"), control: true, applicationCursor: false) == [3])
        #expect(TerminalKeys.bytes(for: .character("Z"), control: true, applicationCursor: false) == [26])
    }

    @Test func controlPunctuation() {
        let expected: [(Character, UInt8)] = [
            ("@", 0), (" ", 0), ("[", 27), ("\\", 28), ("]", 29), ("^", 30), ("_", 31), ("?", 127),
        ]
        for (character, byte) in expected {
            #expect(TerminalKeys.bytes(for: .character(character), control: true, applicationCursor: false) == [byte])
        }
    }

    @Test func controlWithADigitSendsTheDigit() {
        #expect(TerminalKeys.bytes(for: .character("7"), control: true, applicationCursor: false) == [0x37])
    }

    @Test func unicodeIsUTF8() {
        #expect(TerminalKeys.bytes(for: .character("é"), control: false, applicationCursor: false) == [0xC3, 0xA9])
        #expect(TerminalKeys.bytes(for: .character("é"), control: true, applicationCursor: false) == [0xC3, 0xA9])
    }

    @Test func escapeAndTab() {
        #expect(TerminalKeys.bytes(for: .escape, control: false, applicationCursor: false) == [0x1B])
        #expect(TerminalKeys.bytes(for: .tab, control: false, applicationCursor: false) == [0x09])
    }

    @Test func theLatchAppliesOnce() {
        var latch = ControlLatch()
        #expect(!latch.isOn)
        latch.toggle()
        let first = latch.consume()
        let second = latch.consume()
        #expect(first)
        #expect(!second)
        latch.toggle()
        latch.toggle()
        #expect(!latch.isOn)
    }
}
