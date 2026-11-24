import WebKit

/// Media in the pages the app embeds: nothing plays by itself, it plays inline, and it stops when the app locks or goes
/// to the background. The app has no background audio mode, so a page cannot keep sound running behind the lock.
enum WebMedia {
    @MainActor static func configure(_ configuration: WKWebViewConfiguration) {
        configuration.mediaTypesRequiringUserActionForPlayback = .all
        configuration.allowsInlineMediaPlayback = true
        configuration.allowsPictureInPictureMediaPlayback = false
    }
}
