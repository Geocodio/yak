import Testing

@testable import YakKit

struct NotificationOnboardingTests {
    @Test(arguments: [
        (PushAvailability.available, NotificationAuthorization.notDetermined, NotificationOnboarding.Step.explain),
        (.available, .authorized, .register), (.available, .provisional, .register),
        (.available, .ephemeral, .register),
        (.available, .denied, .skip),
        (.noKey, .notDetermined, .skip), (.otherBuild, .notDetermined, .skip),
        (.otherEnvironment(instance: .sandbox), .notDetermined, .skip),
    ])
    func explainsOnlyWhenItCanAndMustAsk(
        availability: PushAvailability, authorization: NotificationAuthorization, expected: NotificationOnboarding.Step
    ) {
        #expect(
            NotificationOnboarding.afterPairing(availability: availability, authorization: authorization) == expected)
    }

    @Test func anUnknownAvailabilityNeverAsks() {
        #expect(NotificationOnboarding.afterPairing(availability: nil, authorization: .notDetermined) == .skip)
    }

    @Test func settingsHints() {
        #expect(NotificationOnboarding.settingsHint(authorization: .notDetermined, anyInstanceCanPush: true) == .turnOn)
        #expect(NotificationOnboarding.settingsHint(authorization: .notDetermined, anyInstanceCanPush: false) == nil)
        #expect(
            NotificationOnboarding.settingsHint(authorization: .denied, anyInstanceCanPush: false)
                == .offInSystemSettings)
        #expect(
            NotificationOnboarding.settingsHint(authorization: .denied, anyInstanceCanPush: true)
                == .offInSystemSettings)
        #expect(NotificationOnboarding.settingsHint(authorization: .authorized, anyInstanceCanPush: true) == nil)
    }
}
