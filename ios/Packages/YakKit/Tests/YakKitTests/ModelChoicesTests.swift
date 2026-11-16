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
