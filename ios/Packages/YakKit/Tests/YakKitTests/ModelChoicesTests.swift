import Foundation
import Testing

@testable import YakKit

struct ModelChoicesTests {
    private func families() throws -> [ModelFamily] {
        try decoded(ThreadShow.self, "scenario-thread-show").composer.models
    }

    @Test func modelRowsHaveTwoLevels() throws {
        let families = try families()
        #expect(ModelChoices.rows(families: families, selected: "sonnet", expanded: []).map(\.id) == ["sonnet"])
        let open = ModelChoices.rows(families: families, selected: "claude-sonnet-5-0", expanded: ["sonnet"])
        #expect(open.map(\.id) == ["sonnet", "claude-sonnet-5-0"])
        #expect(open[1].kind == .pinned && open[1].isSelected && !open[0].isSelected)
        #expect(open[0].kind == .family(hasOlder: true, isExpanded: true) && open[0].detail == "Fast and capable")
    }

    @Test func modelLabels() throws {
        let families = try families()
        #expect(ModelChoices.label(for: "sonnet", in: families) == "Sonnet 5.5")
        #expect(ModelChoices.label(for: "claude-sonnet-5-0", in: families) == "Sonnet 5")
        #expect(ModelChoices.isPinned("claude-sonnet-5-0", in: families))
        #expect(!ModelChoices.isPinned("sonnet", in: families))
        #expect(ModelChoices.label(for: "mystery", in: families) == "mystery")
        #expect(ModelChoices.label(for: nil, in: families) == nil)
    }
}

struct EffortTests {
    private func families(_ json: String) throws -> [ModelFamily] {
        try JSONDecoder().decode([ModelFamily].self, from: Data(json.utf8))
    }

    private let json = """
        [{"family":"opus","latest":{"value":"opus","label":"Opus 5.5","description":"d","efforts":["low","medium","high","xhigh"]},
          "older":[{"value":"opus-5","label":"Opus 5","efforts":["low","medium"]},{"value":"opus-4","label":"Opus 4"}]}]
        """

    @Test func anUnknownComposerEffortDecodesAsNone() throws {
        let decode = { (effort: String) in
            try JSONDecoder().decode(
                Composer.self,
                from: Data(
                    """
                    {"state":"idle","model":"opus","effort":\(effort),"models":[],"nextAttachmentNumber":1}
                    """.utf8))
        }
        #expect(try decode("\"high\"").effort == .high)
        #expect(try decode("\"ultra\"").effort == nil)
        #expect(try decode("null").effort == nil)
    }

    @Test func decodesEffortsAndDefaultsMissingToAll() throws {
        let families = try families(json)
        #expect(families[0].latest.efforts == [.low, .medium, .high, .xhigh])
        #expect(families[0].older[0].efforts == [.low, .medium])
        #expect(families[0].older[1].efforts == EffortLevel.allCases)
        #expect(ModelChoices.efforts(for: "opus-5", in: families) == [.low, .medium])
    }

    @Test func switchingModelClampsEffort() throws {
        let families = try families(json)
        var form = NewThreadForm(
            options: nil, saved: NewThreadDraft(repo: "a/b", mode: "fix", model: "opus", effort: .xhigh),
            lastUsedRepo: nil, linkRepo: nil)
        form.selectModel("opus-5", families: families)
        #expect(form.effort == .medium)
        form.selectEffort(.low)
        form.selectModel("opus-4", families: families)
        #expect(form.effort == .low)
        #expect(form.draft?.effort == .low)
    }

    @Test func labelShowsEffort() throws {
        let families = try families(json)
        #expect(ModelChoices.label(for: "opus", effort: .high, in: families) == "Opus 5.5 · High")
        #expect(ModelChoices.label(for: "opus", effort: nil, in: families) == "Opus 5.5")
    }
}
