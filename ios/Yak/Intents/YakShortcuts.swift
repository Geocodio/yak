import AppIntents

/// The shortcuts that appear in Shortcuts, Spotlight and Siri, and can be bound to the Action button.
struct YakShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(
            intent: StartThreadIntent(),
            phrases: ["Start a thread in \(.applicationName)", "New \(.applicationName) thread"],
            shortTitle: LocalizedStringResource("intent.start.title"), systemImageName: "plus.bubble")
        AppShortcut(
            intent: NeedsMeIntent(),
            phrases: ["What needs me in \(.applicationName)", "What is waiting in \(.applicationName)"],
            shortTitle: LocalizedStringResource("intent.needsMe.title"), systemImageName: "bell.badge")
        AppShortcut(
            intent: OpenLastThreadIntent(),
            phrases: ["Open my last \(.applicationName) thread"],
            shortTitle: LocalizedStringResource("intent.openLast.title"), systemImageName: "arrow.uturn.backward")
    }
}
