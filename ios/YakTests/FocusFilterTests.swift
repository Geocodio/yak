import Foundation
import Testing
import YakKit

@testable import Yak

@Suite struct FocusFilterTests {
    private let home = Instance(
        url: URL(string: "https://Yak.Example.com")!, name: "Example Yak", userName: "Test person",
        lastUsedAt: Date(), canonicalURL: URL(string: "https://alias.example.com")!)
    private let other = Instance(
        url: URL(string: "https://other.example.com")!, name: "Other Yak", userName: "Test person", lastUsedAt: Date())

    @Test func theContextAllowsTheChosenHosts() throws {
        let predicate = try #require(YakFocusFilter.predicate(for: [InstanceEntity(home)]))
        #expect(predicate.evaluate(with: "yak.example.com"))
        #expect(predicate.evaluate(with: "alias.example.com"))
        #expect(!predicate.evaluate(with: "other.example.com"))
    }

    @Test func noChoiceHasNoPredicate() {
        #expect(YakFocusFilter.predicate(for: nil) == nil)
    }

    @Test func anEmptyChoiceLetsNothingThrough() throws {
        let predicate = try #require(YakFocusFilter.predicate(for: []))
        #expect(!predicate.evaluate(with: "yak.example.com"))
    }

    @Test func theDescriptionNamesInstancesOnly() {
        #expect(YakFocusFilter.summary(names: nil) == "All Yaks")
        #expect(YakFocusFilter.summary(names: []) == "No Yaks")
        #expect(YakFocusFilter.summary(names: ["Example Yak"]) == "Example Yak")
        #expect(YakFocusFilter.summary(names: ["Example Yak", "Other Yak"]) == "Example Yak and Other Yak")
        #expect(YakFocusFilter.summary(names: ["Example Yak", "Other Yak", "Third Yak"]) == "Example Yak and 2 more")
    }
}
