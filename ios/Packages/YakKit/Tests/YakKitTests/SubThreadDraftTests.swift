import Foundation
import Testing

@testable import YakKit

@Test(arguments: [
    ("", "Brief", SubThreadDraftProblem.noName),
    (String(repeating: "a", count: 61), "Brief", .nameTooLong),
    ("cache", "   ", .noBrief),
    ("cache", String(repeating: "b", count: 20_001), .briefTooLong),
])
func draftProblems(name: String, brief: String, expected: SubThreadDraftProblem) {
    #expect(SubThreadDraft(name: name, brief: brief).problem == expected)
}

@Test func aDraftRoundTripsWithItsKey() throws {
    let key = UUID()
    let draft = SubThreadDraft(
        name: String(repeating: "é", count: 60), brief: "Check it", kind: .research, model: "sonnet",
        sendKey: key, sendKeyIsInFlight: true)
    let copy = try JSONDecoder().decode(SubThreadDraft.self, from: JSONEncoder().encode(draft))
    #expect(copy == draft && copy.problem == nil && copy.sendKey == key)
    #expect(SubThreadDraft().isEmpty && !SubThreadDraft(kind: .research).isEmpty)
    #expect(SubThreadDraft(name: "  ").isEmpty && SubThreadDraft(name: " x ").trimmedName == "x")
}
