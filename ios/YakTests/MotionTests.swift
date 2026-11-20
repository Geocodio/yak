import SwiftUI
import Testing

@testable import Yak

struct MotionTests {
    @Test func motionRunsTheGivenAnimation() {
        #expect(Motion.resolve(Motion.drawer, reduceMotion: false, isTestMode: false) == Motion.drawer)
    }

    @Test func reduceMotionFadesInstead() {
        #expect(Motion.resolve(Motion.drawer, reduceMotion: true, isTestMode: false) == Motion.crossFade)
        #expect(Motion.resolve(Motion.paneSwitch, reduceMotion: true, isTestMode: false) == Motion.crossFade)
    }

    @Test func testModeIsInstant() {
        #expect(Motion.resolve(Motion.drawer, reduceMotion: false, isTestMode: true) == nil)
        #expect(Motion.resolve(Motion.drawer, reduceMotion: true, isTestMode: true) == nil)
    }

    @Test func aReleaseWithoutDistanceUsesTheDrawerSpring() {
        #expect(Motion.release(velocity: 900, distance: 0) == Motion.drawer)
    }

    @Test func aReleaseCarriesTheFingersSpeed() {
        #expect(Motion.release(velocity: 600, distance: 300) != Motion.release(velocity: 0, distance: 300))
    }

    @Test func aThrownReleaseIsCapped() {
        #expect(Motion.release(velocity: 90_000, distance: 100) == Motion.release(velocity: 900_000, distance: 100))
    }
}

struct HapticTests {
    @Test func everyMomentHasAStyle() {
        #expect(Haptic.drawerSettled.style == .softImpact)
        #expect(Haptic.paneChanged.style == .selection)
        #expect(Haptic.threadSwitched.style == .selection)
        #expect(Haptic.sent.style == .success)
        #expect(Haptic.refreshed.style == .success)
        #expect(Haptic.allCases.count == 5)
    }

    @Test func eachStyleMapsToItsFeedback() {
        #expect(Haptic.drawerSettled.feedback == .impact(weight: .light))
        #expect(Haptic.paneChanged.feedback == .selection)
        #expect(Haptic.sent.feedback == .success)
    }
}
