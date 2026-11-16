import Foundation

/// Hand-written scenario data from `Tests/YakKitTests/Scenarios/`. It never reads `ios/fixtures`.
enum Scenarios {
    static func data(_ name: String) throws -> Data {
        // Read through `#filePath`, like `Fixtures`, so the package needs no resources.
        let folder = URL(fileURLWithPath: #filePath)
            .deletingLastPathComponent().deletingLastPathComponent()
            .appendingPathComponent("Scenarios")
        return try Data(contentsOf: folder.appendingPathComponent("\(name).json"))
    }

    /// The scenario with the value at `path` replaced.
    static func data(_ name: String, setting path: [String], toJSON replacementJSON: String) throws -> Data {
        let replacement = try JSONSerialization.jsonObject(
            with: Data(replacementJSON.utf8), options: .fragmentsAllowed)
        func set(_ value: Any, _ path: ArraySlice<String>) -> Any {
            guard let key = path.first, var dictionary = value as? [String: Any] else { return replacement }
            dictionary[key] = set(dictionary[key] ?? NSNull(), path.dropFirst())
            return dictionary
        }
        return try JSONSerialization.data(
            withJSONObject: set(JSONSerialization.jsonObject(with: data(name)), path[...]))
    }
}

func decoded<T: Decodable>(_ type: T.Type = T.self, _ name: String) throws -> T {
    try JSONDecoder().decode(type, from: Scenarios.data(name))
}
