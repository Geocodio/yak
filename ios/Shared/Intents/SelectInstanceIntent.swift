import AppIntents

/// The widgets' configuration: which Yak to show. With nothing chosen a widget follows the most recently used one.
struct SelectInstanceIntent: WidgetConfigurationIntent {
    static let title = LocalizedStringResource("widget.intent.title", table: "Widgets")
    static let description = IntentDescription(
        LocalizedStringResource("widget.intent.description", table: "Widgets"))

    @Parameter(title: LocalizedStringResource("widget.intent.parameter", table: "Widgets"))
    var instance: InstanceEntity?
}
