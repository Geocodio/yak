import ActivityKit
import SwiftUI
import WidgetKit
import YakKit

/// The thread's Live Activity: lock screen, Dynamic Island and the small family.
struct ThreadLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: ThreadActivityAttributes.self) { context in
            ThreadActivityContextView(context: context) { look, host in
                ThreadActivityFamilyView(look: look, host: host)
            }
            .widgetURL(Self.url(for: context.attributes))
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) {
                    ThreadActivityContextView(context: context) { look, _ in ThreadActivityExpandedLeading(look: look) }
                }
                DynamicIslandExpandedRegion(.trailing) {
                    ThreadActivityContextView(context: context) { look, _ in ThreadActivityExpandedTrailing(look: look) }
                }
                DynamicIslandExpandedRegion(.center) {
                    ThreadActivityContextView(context: context) { look, _ in ThreadActivityExpandedCenter(look: look) }
                }
                DynamicIslandExpandedRegion(.bottom) {
                    ThreadActivityContextView(context: context) { look, _ in ThreadActivityExpandedBottom(look: look) }
                }
            } compactLeading: {
                ThreadActivityContextView(context: context) { look, _ in ThreadActivityCompactLeading(look: look) }
            } compactTrailing: {
                ThreadActivityContextView(context: context) { look, _ in ThreadActivityCompactTrailing(look: look) }
            } minimal: {
                ThreadActivityContextView(context: context) { look, _ in ThreadActivityMinimal(look: look) }
            }
            .widgetURL(Self.url(for: context.attributes))
        }
        .supplementalActivityFamilies([.small])
    }

    /// `yak://thread?instance=<instance>&id=<thread>`; the app rewrites a server address to the paired one.
    static func url(for attributes: ThreadActivityAttributes) -> URL? {
        var components = URLComponents()
        components.scheme = "yak"
        components.host = "thread"
        let address = attributes.instance.addingPercentEncoding(withAllowedCharacters: CharacterSet.alphanumerics.union(CharacterSet(charactersIn: "-._~")))
        components.percentEncodedQueryItems = [
            URLQueryItem(name: "instance", value: address), URLQueryItem(name: "id", value: attributes.threadId),
        ]
        return components.url
    }
}

/// Builds the finished look for a context and reads the width the Dynamic Island has.
private struct ThreadActivityContextView<Content: View>: View {
    let context: ActivityViewContext<ThreadActivityAttributes>
    let content: (LiveActivityLook, String) -> Content
    @Environment(\.isDynamicIslandLimitedInWidth) private var isLimitedWidth

    var body: some View {
        content(
            LiveActivityLook.make(
                content: context.state, title: context.attributes.title, isStale: context.isStale,
                isLimitedWidth: isLimitedWidth),
            URL(string: context.attributes.instance)?.host() ?? context.attributes.instance)
    }
}

/// The lock screen card, or the small family's two lines.
private struct ThreadActivityFamilyView: View {
    let look: LiveActivityLook
    let host: String
    @Environment(\.activityFamily) private var family

    var body: some View {
        switch family {
        case .small: ThreadActivitySmallView(look: look)
        default: ThreadActivityLockScreenView(look: look, host: host)
        }
    }
}
