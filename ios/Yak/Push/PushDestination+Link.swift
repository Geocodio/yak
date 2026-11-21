import Foundation
import YakKit

extension PushDestination {
    /// The `yak://` link a tap on a thread or task push opens, built from the paired instance's address and never
    /// from the payload's, so the tap takes the path of an opened link. Nil for every other destination.
    var linkURL: URL? {
        let kind: String
        let instance: Instance
        let id: String
        var fragment: String?
        switch self {
        case .thread(let match, let threadID, let target):
            (kind, instance, id, fragment) = ("thread", match, threadID, target)
        case .task(let match, let taskID): (kind, instance, id) = ("task", match, taskID)
        case .webPage, .instance, .unknownInstance: return nil
        }
        var components = URLComponents()
        components.scheme = "yak"
        components.host = kind
        var text = instance.url.absoluteString
        while text.hasSuffix("/") { text.removeLast() }
        let address = text.addingPercentEncoding(withAllowedCharacters: .urlUnreserved)
        components.percentEncodedQueryItems = [
            URLQueryItem(name: "instance", value: address), URLQueryItem(name: "id", value: id),
        ]
        // A fragment holds letters, digits, "-" and "_" only, so it needs no escaping.
        if let fragment { components.percentEncodedQueryItems?.append(URLQueryItem(name: "fragment", value: fragment)) }
        return components.url
    }
}

extension CharacterSet {
    /// Letters, digits and `-._~`: what stays readable when an address is written into a query value.
    static let urlUnreserved = CharacterSet.alphanumerics.union(CharacterSet(charactersIn: "-._~"))
}
