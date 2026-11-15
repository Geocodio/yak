/// The one rule for a thread id: exactly eight ASCII letters or digits.
enum ThreadIdentifier {
    static func isValid(_ id: String) -> Bool {
        id.utf8.count == 8
            && id.utf8.allSatisfy {
                ($0 >= 0x30 && $0 <= 0x39) || ($0 >= 0x41 && $0 <= 0x5A) || ($0 >= 0x61 && $0 <= 0x7A)
            }
    }
}
