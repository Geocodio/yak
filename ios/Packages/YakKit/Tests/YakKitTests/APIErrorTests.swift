import Foundation
import Testing

@testable import YakKit

@Test func jsonValueRoundTrips() throws {
    let text = #"{"a":[1,"b",null,true],"c":{"d":2.5}}"#
    let value = try JSONDecoder().decode(JSONValue.self, from: Data(text.utf8))
    #expect(
        value
            == .object([
                "a": .array([.number(1), .string("b"), .null, .bool(true)]), "c": .object(["d": .number(2.5)]),
            ]))
    let again = try JSONDecoder().decode(JSONValue.self, from: try JSONEncoder().encode(value))
    #expect(again == value)
}

@Test func parsesTheErrorShapeAndRejectsOthers() {
    let parsed = APIError.parse(status: 422, body: Data(#"{"error":"invalid","message":"Bad"}"#.utf8))
    #expect(parsed == APIError(status: 422, code: "invalid", message: "Bad", details: [:]))
    #expect(APIError.parse(status: 422, body: Data(#"{"foo":1}"#.utf8)) == nil)
    #expect(APIError.parse(status: 422, body: Data("<html>".utf8)) == nil)
}
