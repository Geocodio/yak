import SwiftUI

/// Turns Markdown text into a finished document off the main actor and draws that document on it.
protocol MarkdownRendering: Sendable {
    associatedtype Document: Sendable
    nonisolated func parse(_ markdown: String) async -> Document
    @MainActor func view(_ document: Document) -> AnyView
}

/// The one place that names the renderer the app uses.
typealias AppMarkdownRenderer = SwiftMarkdownRenderer
