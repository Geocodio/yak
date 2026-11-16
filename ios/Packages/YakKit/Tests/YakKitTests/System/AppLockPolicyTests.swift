import Foundation
import Testing

@testable import YakKit

private let start = Date(timeIntervalSince1970: 1_800_000_000)

private func at(_ seconds: TimeInterval) -> Date { start.addingTimeInterval(seconds) }

private func unlockedPolicy() -> AppLockPolicy {
    var policy = AppLockPolicy(isOn: true)
    policy.handle(.launched)
    policy.handle(.unlocked)
    return policy
}

struct AppLockPolicyTests {
    @Test func onlyANewLockAsksByItself() {
        var off = AppLockPolicy(isOn: false)
        #expect(off.handle(.launched) == false)

        var policy = AppLockPolicy(isOn: true)
        #expect(policy.handle(.launched) == true)
        #expect(policy.handle(.becameActive(at(0))) == false)
        #expect(policy.handle(.enteredBackground(at(0))) == false)
        #expect(policy.handle(.becameActive(at(400))) == false)
        #expect(policy.isLocked)

        var open = unlockedPolicy()
        #expect(open.handle(.enteredBackground(at(0))) == false)
        #expect(open.handle(.becameActive(at(300))) == true)
        #expect(open.handle(.becameActive(at(301))) == false)

        var brief = unlockedPolicy()
        brief.handle(.enteredBackground(at(0)))
        #expect(brief.handle(.becameActive(at(10))) == false)

        var other = unlockedPolicy()
        #expect(other.handle(.unlocked) == false)
        #expect(other.handle(.turnedOn) == false)
        #expect(other.handle(.turnedOff) == false)
        #expect(other.handle(.passcodeRemoved) == false)
    }

    @Test func offByDefaultNeverLocks() {
        var policy = AppLockPolicy(isOn: false)
        policy.handle(.launched)
        policy.handle(.enteredBackground(at(0)))
        policy.handle(.becameActive(at(36_000)))
        #expect(!policy.isLocked)
    }

    @Test func launchLocksWhenOn() {
        var policy = AppLockPolicy(isOn: true)
        policy.handle(.launched)
        #expect(policy.isLocked)
    }

    @Test func fiveMinutesAwayLocks() {
        var short = unlockedPolicy()
        short.handle(.enteredBackground(at(0)))
        short.handle(.becameActive(at(299)))
        #expect(!short.isLocked)

        var long = unlockedPolicy()
        long.handle(.enteredBackground(at(0)))
        long.handle(.becameActive(at(300)))
        #expect(long.isLocked)
    }

    @Test func aClockThatWentBackwardsLocks() {
        var policy = unlockedPolicy()
        policy.handle(.enteredBackground(at(0)))
        policy.handle(.becameActive(at(-1)))
        #expect(policy.isLocked)
    }

    @Test func aTimeThatIsNotFiniteLocks() {
        let nan = Date(timeIntervalSinceReferenceDate: .nan)
        let infinity = Date(timeIntervalSinceReferenceDate: .infinity)
        let pairs = [(nan, start), (start, nan), (infinity, start), (start, infinity)]
        for (background, active) in pairs {
            var policy = unlockedPolicy()
            policy.handle(.enteredBackground(background))
            policy.handle(.becameActive(active))
            #expect(policy.isLocked)
        }
    }

    @Test func activeWithoutBackgroundDoesNotLock() {
        var policy = unlockedPolicy()
        policy.handle(.becameActive(at(10_000)))
        #expect(!policy.isLocked)
    }

    @Test func theEarliestBackgroundCounts() {
        var policy = unlockedPolicy()
        policy.handle(.enteredBackground(at(0)))
        policy.handle(.enteredBackground(at(200)))
        policy.handle(.becameActive(at(310)))
        #expect(policy.isLocked)
    }

    @Test func unlockingForgetsTheBackgroundTime() {
        var policy = unlockedPolicy()
        policy.handle(.enteredBackground(at(0)))
        policy.handle(.becameActive(at(400)))
        #expect(policy.isLocked)
        policy.handle(.unlocked)
        policy.handle(.becameActive(at(10_000)))
        #expect(!policy.isLocked)
    }

    @Test func turningOnDoesNotLockAndTurningOffUnlocks() {
        var policy = AppLockPolicy(isOn: false)
        policy.handle(.turnedOn)
        #expect(policy.isOn && !policy.isLocked)
        policy.handle(.enteredBackground(at(0)))
        policy.handle(.turnedOff)
        policy.handle(.turnedOn)
        policy.handle(.becameActive(at(1_000)))
        #expect(!policy.isLocked)
        policy.handle(.launched)
        policy.handle(.turnedOff)
        #expect(!policy.isOn && !policy.isLocked)
    }

    @Test func aRemovedPasscodeTurnsTheLockOff() {
        var policy = AppLockPolicy(isOn: true)
        policy.handle(.launched)
        #expect(policy.isLocked)
        policy.handle(.passcodeRemoved)
        #expect(!policy.isOn && !policy.isLocked)
        #expect(policy.turnedOffForMissingPasscode)
        policy.acknowledgeTurnedOff()
        #expect(!policy.turnedOffForMissingPasscode)

        var off = AppLockPolicy(isOn: false)
        off.handle(.passcodeRemoved)
        #expect(!off.turnedOffForMissingPasscode)
    }

    @Test func theLimitCannotGrow() {
        for limit in [10_000, .infinity, .nan] as [TimeInterval] {
            var policy = AppLockPolicy(isOn: true, backgroundLimit: limit)
            policy.handle(.launched)
            policy.handle(.unlocked)
            policy.handle(.enteredBackground(at(0)))
            policy.handle(.becameActive(at(299)))
            #expect(!policy.isLocked)
            policy.handle(.enteredBackground(at(1_000)))
            policy.handle(.becameActive(at(1_300)))
            #expect(policy.isLocked)
        }

        var negative = AppLockPolicy(isOn: true, backgroundLimit: -5)
        negative.handle(.launched)
        negative.handle(.unlocked)
        negative.handle(.enteredBackground(at(0)))
        negative.handle(.becameActive(at(0)))
        #expect(negative.isLocked)
    }

    @Test func theCoverRule() {
        let table: [(isOn: Bool, isLocked: Bool, sceneIsActive: Bool, covers: Bool)] = [
            (false, false, false, false), (false, false, true, false),
            (false, true, false, true), (false, true, true, true),
            (true, false, false, true), (true, false, true, false),
            (true, true, false, true), (true, true, true, true),
        ]
        for row in table {
            #expect(
                AppLockPolicy.coversContent(isOn: row.isOn, isLocked: row.isLocked, sceneIsActive: row.sceneIsActive)
                    == row.covers)
        }
    }

    @Test func wordingFollowsTheReportedBiometry() {
        let expected: [(LockBiometry, AppLockMethod)] = [
            (.faceID, .faceID), (.touchID, .touchID), (.opticID, .opticID),
            (.none, .passcode), (.other, .deviceUnlock),
        ]
        for (biometry, method) in expected {
            #expect(AppLockMethod.make(LockCapability(biometry: biometry, hasPasscode: true)) == method)
            #expect(AppLockMethod.make(LockCapability(biometry: biometry, hasPasscode: false)) == nil)
        }
    }
}
