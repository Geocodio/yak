import ActivityKit
import YakKit

/// The attributes of a thread's Live Activity. The type name is the push's `attributes-type`, so it is never renamed.
/// The content state is the YakKit type, whose shape is tested against the server's fixtures.
struct ThreadActivityAttributes: ActivityAttributes {
    typealias ContentState = ThreadActivityContentState

    /// The paired instance's address.
    let instance: String
    let threadId: String
    /// A private thread's title is the guarded "Private thread"; the views never show it.
    let title: String
}
