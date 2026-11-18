import Foundation

/// Hand-written values for the state gallery. Neutral names only; a private thread shows as "A private thread".
enum GallerySamples {
    static let instanceName = "Example Yak"
    static let address = "yak.example.com"
    static let threadTitle = "Retry failed rows when the import times out"
    static let privateThreadTitle = "A private thread"
    static let serverError = "Example Yak could not be reached. Try again in a moment."

    /// Every piece of sample text, so a test can look for what must never appear.
    static let allText = [instanceName, address, threadTitle, privateThreadTitle, serverError]
}
