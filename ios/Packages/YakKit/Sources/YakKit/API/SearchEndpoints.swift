import Foundation

extension Endpoint where Response == SearchAnswer {
    /// The server asks for two to 100 characters (code points); the text is trimmed and cut to fit. Sent once: the search state
    /// decides when to ask again.
    public static func search(_ text: String) -> Endpoint<SearchAnswer> {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        let cut = String(String.UnicodeScalarView(trimmed.unicodeScalars.prefix(100)))
        return Endpoint(
            method: .get, path: "search", query: [URLQueryItem(name: "q", value: cut)],
            retriesTransientFailures: false)
    }
}
