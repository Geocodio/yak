import Foundation
import Testing

@testable import YakKit

@Test func meDecodesTheEmbedSupport() throws {
    let me = try JSONDecoder().decode(Me.self, from: Fixtures.data("me"))
    #expect(me.web == Me.WebSupport(embed: 1) && me.supportsEmbeddedThread)
}

@Test func meWithoutWebMeansNoEmbeddedThread() throws {
    var object = try #require(JSONSerialization.jsonObject(with: Fixtures.data("me")) as? [String: Any])
    object["web"] = nil
    let me = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
    #expect(me.web == nil && !me.supportsEmbeddedThread)
}

@Test func meWithEmbedZeroOrAnUnknownKeyIsNotSupported() throws {
    var object = try #require(JSONSerialization.jsonObject(with: Fixtures.data("me")) as? [String: Any])
    object["web"] = ["embed": 0, "later": true]
    let me = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
    #expect(!me.supportsEmbeddedThread)
}

@Test(arguments: ["{}", #"{"embed":"1"}"#, #"{"embed":1.5}"#, #"{"embed":null}"#])
func aWebObjectWithoutAnIntegerEmbedDecodesAsZero(web: String) throws {
    var object = try #require(JSONSerialization.jsonObject(with: Fixtures.data("me")) as? [String: Any])
    object["web"] = try JSONSerialization.jsonObject(with: Data(web.utf8))
    let me = try JSONDecoder().decode(Me.self, from: JSONSerialization.data(withJSONObject: object))
    #expect(me.web == Me.WebSupport(embed: 0) && !me.supportsEmbeddedThread)
}
