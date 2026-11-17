import SwiftUI
import YakKit

struct DebugGalleryEntry: Identifiable {
    let id: String
    let area: String
    let title: String
    let make: @MainActor () -> AnyView
}

/// State screens built from hand-written samples, so the gallery also works in a TestFlight build, where no stub
/// runs. Only views that take plain values are listed; a state that lives inside a model-driven screen is reached
/// through the stub scenarios of the Debug walkthrough instead.
enum DebugGallery {
    /// The order of the sections.
    static let areas = [
        "Pairing", "Threads", "Chat", "Preview", "Files", "Terminal", "Overview", "Tasks", "Push", "Settings",
        "App lock",
    ]

    /// One line per entry. A feature merged later adds its entries here, one line each.
    @MainActor static let entries: [DebugGalleryEntry] =
        pairing + threads + tasks + settings + appLock

    // MARK: Pairing

    @MainActor private static let pairing: [DebugGalleryEntry] = [
        ("unreachable", "The instance cannot be reached", PairingProblem.unreachable),
        ("serverTooOld", "The instance is too old", .serverTooOld),
        ("appTooOld", "This app is too old", .appTooOld),
        ("codeExpired", "The pairing code expired", .codeExpired),
        ("codeInvalid", "The pairing code is not valid", .codeInvalid),
        ("denied", "Pairing was denied", .denied),
    ].map { identifier, title, problem in
        DebugGalleryEntry(id: "pairing.\(identifier)", area: "Pairing", title: title) {
            AnyView(
                PairingProblemView(problem: problem, address: GallerySamples.address, retry: {})
                    .padding(24).padding(.top, 40))
        }
    }

    // MARK: Threads

    @MainActor private static let threads: [DebugGalleryEntry] = [
        ("loading", "A thread is loading", ThreadLoadPresentation.loading),
        ("slow", "A thread is slow to load", .slow),
        ("notFound", "The thread was not found", .notFound),
        ("offline", "Offline with no saved copy", .offlineWithoutCopy),
        ("serverError", "The server answered with an error", .serverError(message: GallerySamples.serverError)),
    ].map { identifier, title, presentation in
        DebugGalleryEntry(id: "threads.\(identifier)", area: "Threads", title: title) {
            AnyView(ThreadLoadView(presentation: presentation, retry: {}, openThreads: {}))
        }
    }

    // MARK: Tasks

    @MainActor private static let tasks: [DebugGalleryEntry] = [
        DebugGalleryEntry(id: "tasks.loading", area: "Tasks", title: "The task list is loading") {
            AnyView(RowSkeleton(identifier: "gallery.skeleton"))
        },
        DebugGalleryEntry(id: "tasks.offline", area: "Tasks", title: "A list is offline") {
            AnyView(
                VStack {
                    NoticeLine(text: "common.offline", systemImage: "wifi.slash", identifier: "gallery.notice")
                    Spacer()
                })
        },
    ]

    // MARK: Settings

    @MainActor private static let settings: [DebugGalleryEntry] =
        [
            DebugGalleryEntry(
                id: "settings.unreachable", area: "Settings", title: "The instance cannot be reached"
            ) {
                problem(LocalizedStringKey("signedIn.unreachable \(GallerySamples.instanceName)"))
            }
        ]
        + [
            ("appTooOld", "This app is too old", "signedIn.appTooOld"),
            ("serverTooOld", "The instance is too old", "signedIn.serverTooOld"),
            ("tokenUnreadable", "The saved token cannot be read", "signedIn.tokenUnreadable"),
            ("failed", "Refreshing failed", "signedIn.failed"),
        ].map { identifier, title, key in
            DebugGalleryEntry(id: "settings.\(identifier)", area: "Settings", title: title) {
                problem(LocalizedStringKey(key))
            }
        }

    @MainActor private static func problem(_ message: LocalizedStringKey) -> AnyView {
        AnyView(List { InstanceProblemView(message: message, retry: {}) }.scrollContentBackground(.hidden))
    }

    // MARK: App lock

    @MainActor private static let appLock: [DebugGalleryEntry] = [
        DebugGalleryEntry(id: "appLock.locked", area: "App lock", title: "The lock screen") {
            AnyView(GalleryLockScreen())
        },
        DebugGalleryEntry(id: "appLock.cover", area: "App lock", title: "The app switcher cover") {
            AnyView(AppSwitcherCover())
        },
    ]
}

/// The lock screen over a controller that is locked and always cancels, so it stays idle.
private struct GalleryLockScreen: View {
    @State private var controller: AppLockController = {
        let settings = PrivacySettings(store: GalleryStore())
        settings.appLockOn = true
        return AppLockController(settings: settings, authenticator: GalleryAuthenticator(), clock: SystemClock())
    }()

    var body: some View {
        AppLockScreen(controller: controller).task { await controller.refreshCapability() }
    }
}

private final class GalleryStore: KeyValueStore, @unchecked Sendable {
    private let lock = NSLock()
    private var values: [String: Data] = [:]
    func data(forKey key: String) -> Data? { lock.withLock { values[key] } }
    func set(_ value: Data?, forKey key: String) { lock.withLock { values[key] = value } }
}

private struct GalleryAuthenticator: DeviceOwnerAuthenticator {
    func capability() async -> CapabilityReading { .known(LockCapability(biometry: .faceID, hasPasscode: true)) }
    func authenticate(reason: String) async -> DeviceOwnerAuthenticationResult { .cancelled }
    func cancel() {}
}

/// The gallery's list and the full-screen view of one entry.
struct DebugGalleryScreen: View {
    @State private var opened: DebugGalleryEntry?

    var body: some View {
        List {
            ForEach(DebugGallery.areas, id: \.self) { area in
                let rows = DebugGallery.entries.filter { $0.area == area }
                if !rows.isEmpty {
                    Section {
                        ForEach(rows) { entry in
                            Button {
                                opened = entry
                            } label: {
                                Text(verbatim: entry.title).foregroundStyle(Color.primary)
                                    .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                                    .contentShape(.rect)
                            }
                            .accessibilityIdentifier("gallery.\(entry.id)")
                        }
                    } header: {
                        Text(verbatim: area)
                    }
                }
            }
        }
        .navigationTitle("debug.gallery.title")
        .navigationBarTitleDisplayMode(.inline)
        .accessibilityIdentifier("gallery.screen")
        .fullScreenCover(item: $opened) { entry in
            ZStack(alignment: .top) {
                entry.make().frame(maxWidth: .infinity, maxHeight: .infinity)
                HStack(alignment: .top) {
                    Text(verbatim: entry.title).font(.footnote.weight(.semibold))
                        .padding(.horizontal, 12).padding(.vertical, 6)
                        .background(Color(.systemBackground), in: .capsule)
                        .overlay(Capsule().stroke(Color.primary.opacity(0.2)))
                    Spacer(minLength: 8)
                    Button("debug.gallery.close") { opened = nil }
                        .buttonStyle(.borderedProminent).tint(.primary)
                        .accessibilityIdentifier("gallery.close")
                }
                .padding(.horizontal, 16).padding(.top, 8)
            }
            .accessibilityElement(children: .contain)
            .accessibilityIdentifier("gallery.entry.\(entry.id)")
        }
    }
}
