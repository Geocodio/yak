import SwiftUI
import YakKit

/// What the lock window shows while the app is locked: opaque, neutral, one button.
struct AppLockScreen: View {
    let controller: AppLockController
    @ScaledMetric(relativeTo: .largeTitle) private var iconSize = 64

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                Image(systemName: "lock.fill")
                    .font(.system(size: iconSize)).foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
                Text("lock.title").font(.title2.weight(.semibold)).multilineTextAlignment(.center)
                    .padding(.top, 20).accessibilityAddTraits(.isHeader)
                if controller.showsPasscodeRemoved && !controller.isLocked {
                    Text("lock.passcodeRemoved").font(.body).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.center).padding(.top, 8)
                    Button("lock.continue") { controller.dismissPasscodeRemoved() }
                        .buttonStyle(LockButtonStyle()).padding(.top, 32)
                        .accessibilityIdentifier("lock.unlock")
                } else {
                    Text("lock.subtitle").font(.body).foregroundStyle(Color.yakMuted)
                        .multilineTextAlignment(.center).padding(.top, 8)
                    unlockButton.padding(.top, 32)
                    if controller.unlock == .failed {
                        Text("lock.failed").font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                            .multilineTextAlignment(.center).padding(.top, 12)
                    }
                }
            }
            .frame(maxWidth: 420)
            .padding(24)
            .frame(maxWidth: .infinity)
        }
        .scrollBounceBehavior(.basedOnSize)
        .background(Color(.systemBackground))
        .accessibilityElement(children: .contain)
        .accessibilityAddTraits(.isModal)
        .accessibilityIdentifier("lock.screen")
    }

    private var unlockButton: some View {
        Button {
            Task { await controller.requestUnlock() }
        } label: {
            if controller.unlock == .authenticating {
                HStack(spacing: 8) {
                    ProgressView().tint(Color(.systemBackground))
                    Text("lock.unlocking")
                }
            } else {
                Text(title)
            }
        }
        .buttonStyle(LockButtonStyle())
        .disabled(controller.unlock == .authenticating)
        .accessibilityIdentifier("lock.unlock")
    }

    private var title: LocalizedStringKey {
        switch controller.method {
        case .faceID?: "lock.unlock.faceID"
        case .touchID?: "lock.unlock.touchID"
        case .opticID?: "lock.unlock.opticID"
        case .passcode?: "lock.unlock.passcode"
        case .deviceUnlock?, nil: "lock.unlock.device"
        }
    }
}

/// The board's dark button: the text color as the fill and the page color as the text.
private struct LockButtonStyle: ButtonStyle {
    @Environment(\.isEnabled) private var isEnabled

    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.headline)
            .foregroundStyle(Color(.systemBackground))
            .frame(maxWidth: .infinity, minHeight: 44)
            .padding(.vertical, 6)
            .background(Color.primary.opacity(configuration.isPressed ? 0.85 : 1), in: .rect(cornerRadius: 14))
            .opacity(isEnabled ? 1 : 0.7)
    }
}

/// What the app switcher shows while the lock is on and the scene is not active.
struct AppSwitcherCover: View {
    @ScaledMetric(relativeTo: .largeTitle) private var iconSize = 64

    var body: some View {
        ZStack {
            Color(.systemBackground)
            Image(systemName: "lock.fill").font(.system(size: iconSize)).foregroundStyle(Color.yakMuted)
        }
        .ignoresSafeArea()
        .accessibilityHidden(true)
        .accessibilityIdentifier("lock.cover")
    }
}
