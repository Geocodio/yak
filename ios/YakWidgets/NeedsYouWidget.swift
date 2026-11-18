import SwiftUI
import WidgetKit
import YakKit

/// "Needs you": the count and the first items waiting for the person on one Yak.
struct NeedsYouWidget: Widget {
    static let kind = "NeedsYou"

    var body: some WidgetConfiguration {
        AppIntentConfiguration(kind: Self.kind, intent: SelectInstanceIntent.self, provider: YakTimelineProvider()) {
            entry in
            NeedsYouEntryView(entry: entry)
        }
        .configurationDisplayName(LocalizedStringResource("widget.needsYou.name", table: "Widgets"))
        .description(LocalizedStringResource("widget.needsYou.description", table: "Widgets"))
        .supportedFamilies([.systemSmall, .systemMedium, .accessoryRectangular, .accessoryCircular])
        .pushHandler(YakWidgetPushHandler.self)
    }
}

struct NeedsYouEntryView: View {
    let entry: YakEntry
    @Environment(\.widgetFamily) private var family

    var body: some View {
        let size = family.yakSize
        let content = NeedsYouWidgetContent.make(
            snapshot: entry.refresh.snapshot, state: entry.refresh.state, instance: entry.refresh.instance, size: size,
            now: entry.date)
        let display = WidgetDisplay(content)
        WidgetBody(display: display, size: size, now: entry.date)
            .redacted(reason: entry.isPlaceholder ? .placeholder : [])
            .widgetURL(family == .systemMedium ? nil : content.link)
            .containerBackground(for: .widget) { WidgetChrome.background }
    }
}

extension WidgetFamily {
    /// The size class YakKit chooses rows for.
    var yakSize: WidgetFamilySize {
        switch self {
        case .systemMedium: .medium
        case .accessoryRectangular: .accessoryRectangular
        case .accessoryCircular: .accessoryCircular
        default: .small
        }
    }
}
