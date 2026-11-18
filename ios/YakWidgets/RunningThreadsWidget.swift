import SwiftUI
import WidgetKit
import YakKit

/// "Running threads": what Yak is working on for the person on one Yak.
struct RunningThreadsWidget: Widget {
    static let kind = "RunningThreads"

    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: Self.kind, intent: SelectInstanceIntent.self, provider: YakTimelineProvider()) {
            entry in
            RunningEntryView(entry: entry)
        }
        .configurationDisplayName(LocalizedStringResource("widget.running.name", table: "Widgets"))
        .description(LocalizedStringResource("widget.running.description", table: "Widgets"))
        .supportedFamilies([.systemSmall, .systemMedium])
        .pushHandler(YakWidgetPushHandler.self)
    }
}

struct RunningEntryView: View {
    let entry: YakEntry
    @Environment(\.widgetFamily) private var family

    var body: some View {
        let content = RunningWidgetContent.make(
            snapshot: entry.refresh.snapshot, state: entry.refresh.state, instance: entry.refresh.instance,
            size: family.yakSize, now: entry.date)
        let display = WidgetDisplay(content)
        WidgetBody(display: display, size: family.yakSize, now: entry.date)
            .redacted(reason: entry.isPlaceholder ? .placeholder : [])
            .widgetURL(family == .systemMedium ? nil : content.link)
            .containerBackground(for: .widget) { WidgetChrome.background }
    }
}
