import Foundation
import Testing

@testable import YakKit

private struct SplitMix64: RandomNumberGenerator {
    var state: UInt64
    mutating func next() -> UInt64 {
        state &+= 0x9E37_79B9_7F4A_7C15
        var z = state
        z = (z ^ (z >> 30)) &* 0xBF58_476D_1CE4_E5B9
        z = (z ^ (z >> 27)) &* 0x94D0_49BB_1331_11EB
        return z ^ (z >> 31)
    }
}

private struct Size {
    let name: String
    let inputs: LayoutInputs
}

/// One size per arrangement, plus a side-by-side size too short for the Overview panel.
private let sizes: [Size] = [
    Size(
        name: "single",
        inputs: LayoutInputs(
            isCompactWidth: true, isCompactHeight: false, detailWidth: 466, hasVerticalToolbarEdge: true)),
    Size(
        name: "sideBySidePanel",
        inputs: LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: 867, hasVerticalToolbarEdge: true)),
    Size(
        name: "sideBySideNoPanel",
        inputs: LayoutInputs(
            isCompactWidth: false, isCompactHeight: true, detailWidth: 867, hasVerticalToolbarEdge: true)),
    Size(
        name: "stacked",
        inputs: LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: 669, hasVerticalToolbarEdge: false)),
    Size(
        name: "book",
        inputs: LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: 867, hasVerticalToolbarEdge: true,
            posture: .partlyOpen, fold: .vertical)),
    Size(
        name: "laptop",
        inputs: LayoutInputs(
            isCompactWidth: false, isCompactHeight: false, detailWidth: 669, hasVerticalToolbarEdge: true,
            posture: .partlyOpen, fold: .horizontal)),
]

private let availableSets: [[PaneID]] = [
    PaneID.allCases, [.chat, .preview, .overview], [.chat, .files, .terminal], [.chat, .overview],
]

private func arrangement(_ size: Size, _ available: [PaneID]) -> PaneArrangement {
    ThreadLayout.arrangement(size.inputs, slotPaneCount: Set(available).count)
}

private func plan(_ state: PaneState, _ size: Size, _ available: [PaneID]) -> SlotPlan {
    PaneLayoutRules.plan(state, available: available, inputs: size.inputs)
}

private func changed(_ state: PaneState, from old: Size, to new: Size, _ available: [PaneID]) -> PaneState {
    PaneLayoutRules.arrangementChanged(
        from: arrangement(old, available), to: arrangement(new, available), state: state, available: available,
        inputs: new.inputs)
}

private func isFirstOfPair(_ position: SlotPosition) -> Bool { position == .leading || position == .lower }

private func key(_ plan: SlotPlan) -> String {
    plan.arrangement.rawValue + "|" + plan.slots.map { "\($0.position.rawValue):\($0.content)" }.joined(separator: ",")
        + "|focus:\(plan.focusedPosition.rawValue)"
}

/// Every plan the next step can produce from `state`, labelled.
private func nextPlans(
    _ state: PaneState, _ size: Size, _ available: [PaneID], includingSizeChanges: Bool
) -> [String: SlotPlan] {
    var result: [String: SlotPlan] = [:]
    let current = plan(state, size, available)
    let froms: [SlotPosition?] = [nil] + current.slots.map { $0.position }
    for pane in PaneID.allCases {
        for from in froms {
            let next = PaneLayoutRules.select(pane, from: from, state: state, plan: current)
            result["select \(pane) from \(String(describing: from))"] = plan(next, size, available)
        }
    }
    for slot in current.slots {
        let next = PaneLayoutRules.focus(slot.position, state: state, plan: current)
        result["focus \(slot.position)"] = plan(next, size, available)
    }
    for other in sizes where includingSizeChanges {
        result["change to \(other.name)"] = plan(
            changed(state, from: size, to: other, available), other, available)
    }
    return result
}

private func shownSet(_ plan: SlotPlan) -> Set<PaneID> {
    Set(plan.slots.map { $0.content == .overviewPanel ? PaneID.overview : $0.content.pane })
}

extension SlotContent {
    fileprivate var pane: PaneID {
        if case .pane(let pane) = self { return pane }
        return .overview
    }
}

/// A walk applies random operations and checks the plan's invariants after every step.
@Suite struct PaneLayoutPropertyTests {
    private static let seeds: [UInt64] = Array(1...24)
    private static let stepsPerSeed = 250

    @Test func randomWalksKeepEveryInvariant() throws {
        var failures: [String] = []
        // Plans seen per (size, plan key): the first state that showed it, and what it does next.
        var representatives: [String: [String: SlotPlan]] = [:]
        var visibleBehaviour: [String: [String: SlotPlan]] = [:]

        for seed in Self.seeds {
            var generator = SplitMix64(state: seed)
            let available = availableSets[Int(seed) % availableSets.count]
            var size = sizes[Int(seed) % sizes.count]
            func randomPane() -> PaneID? {
                Bool.random(using: &generator) ? nil : PaneID.allCases.randomElement(using: &generator)
            }
            var state = PaneLayoutRules.availableChanged(
                state: PaneState(
                    focused: PaneID.allCases.randomElement(using: &generator)!, second: randomPane(),
                    secondLeads: Bool.random(using: &generator), behind: randomPane()),
                available: available, inputs: size.inputs)
            var trace = ["start \(state) at \(size.name) available \(available)"]

            func fail(_ message: String) {
                failures.append("seed \(seed): \(message)\n    " + trace.suffix(6).joined(separator: "\n    "))
            }

            for _ in 0..<Self.stepsPerSeed {
                let before = plan(state, size, available)
                let sizeBefore = size
                switch Int.random(in: 0..<5, using: &generator) {
                case 0, 1:
                    let pane = PaneID.allCases.randomElement(using: &generator)!
                    let from = ([nil] + before.slots.map { Optional($0.position) }).randomElement(using: &generator)!
                    trace.append("select \(pane) from \(String(describing: from))")
                    state = PaneLayoutRules.select(pane, from: from, state: state, plan: before)
                case 2:
                    let slot = before.slots.randomElement(using: &generator)!.position
                    trace.append("focus \(slot)")
                    state = PaneLayoutRules.focus(slot, state: state, plan: before)
                default:
                    let new = sizes.randomElement(using: &generator)!
                    trace.append("change \(size.name) to \(new.name)")
                    state = changed(state, from: size, to: new, available)
                    size = new
                }
                let after = plan(state, size, available)

                // (4) a pane is never shown twice, only available panes are shown, and the focused pane is shown.
                let panes = after.slots.map { $0.content.pane }
                if Set(panes).count != panes.count { fail("a pane is shown twice: \(key(after))") }
                if !Set(panes).isSubset(of: Set(available)) { fail("an unavailable pane is shown: \(key(after))") }
                if after.slots.filter({ $0.content == .overviewPanel }).count > 1
                    || (after.slots.contains { $0.content == .overviewPanel } && !after.overviewIsPanel)
                {
                    fail("the panel is shown where it cannot be: \(key(after))")
                }

                if size.name != sizeBefore.name || trace.last!.hasPrefix("change") {
                    // (1) between two-slot arrangements every shown pane keeps its place and the focus stays.
                    // The panel always takes the trailing slot, so a step that has the panel at either end is
                    // checked for the panes that remain, not for the Overview.
                    // The laptop pose always puts Chat in the lower slot, so it is exempt.
                    let involvesLaptop = [sizeBefore, size].contains { arrangement($0, available) == .laptop }
                    if before.slots.count == 2 && after.slots.count == 2 && !involvesLaptop {
                        let panelInvolved = [before, after].contains {
                            $0.slots.contains { $0.content == .overviewPanel }
                        }
                        let beforePlaces = Dictionary(
                            uniqueKeysWithValues: before.slots.map { ($0.content.pane, isFirstOfPair($0.position)) })
                        let afterPlaces = Dictionary(
                            uniqueKeysWithValues: after.slots.map { ($0.content.pane, isFirstOfPair($0.position)) })
                        if panelInvolved {
                            if shownSet(before) != shownSet(after) {
                                fail("a pane was lost across the panel: \(key(before)) -> \(key(after))")
                            }
                        } else {
                            if beforePlaces != afterPlaces {
                                fail("a pane moved between two-slot layouts: \(key(before)) -> \(key(after))")
                            }
                            if before.slots.first(where: { $0.position == before.focusedPosition })?.content
                                != after.slots.first(where: { $0.position == after.focusedPosition })?.content
                            {
                                fail("the focus moved between two-slot layouts: \(key(before)) -> \(key(after))")
                            }
                        }
                    }
                }

                // (3) a fold to one slot and back returns the same ordered slots.
                if after.slots.count == 2 {
                    for single in sizes where arrangement(single, available).slotCount == 1 {
                        let folded = changed(state, from: size, to: single, available)
                        let back = changed(folded, from: single, to: size, available)
                        let again = plan(back, size, available)
                        if again.slots != after.slots || again.focusedPosition != after.focusedPosition {
                            fail("a fold and an unfold changed the plan: \(key(after)) -> \(key(again))")
                        }
                    }
                }

                // (3b) a change to any size and back returns the same plan.
                if after.slots.count == 2 {
                    for other in sizes {
                        let there = changed(state, from: size, to: other, available)
                        let back = plan(changed(there, from: other, to: size, available), size, available)
                        if back.slots != after.slots || back.focusedPosition != after.focusedPosition {
                            fail("a change to \(other.name) and back changed the plan: \(key(after)) -> \(key(back))")
                        }
                    }
                }

                // (5) the encoded state behaves the same.
                let decoded = try JSONDecoder().decode(PaneState.self, from: JSONEncoder().encode(state))
                if decoded != state { fail("the encoded state decoded to another state") }

                // (2) two states that show the same plan, and hold the same pair, behave the same under every next
                // step. The pair is the whole record, so nothing else may matter.
                let record =
                    "\(state.focused) \(String(describing: state.second)) \(state.secondLeads) "
                    + "\(String(describing: state.behind))"
                let planKey = "\(available.map(\.rawValue))" + size.name + "|" + key(after)
                if after.slots.count == 2 {
                    let next = nextPlans(state, size, available, includingSizeChanges: true)
                    if let known = representatives[planKey + record] {
                        for (label, plan) in next.sorted(by: { $0.key < $1.key }) where known[label] != plan {
                            fail("same plan and pair \(planKey) \(record), different next step \(label)")
                            break
                        }
                    } else {
                        representatives[planKey + record] = next
                    }
                    // (2b) a pick or a tap depends on what is shown (and the pane the panel covers), not on the
                    // history behind it.
                    var visibleKey = planKey
                    if after.slots.contains(where: { $0.content == .overviewPanel }) {
                        let isOverviewFirst = state.focused == .overview ? !state.secondLeads : state.secondLeads
                        visibleKey += "|behind:\(String(describing: state.behind))|overviewFirst:\(isOverviewFirst)"
                    }
                    let picks = nextPlans(state, size, available, includingSizeChanges: false)
                    if let known = visibleBehaviour[visibleKey] {
                        for (label, plan) in picks.sorted(by: { $0.key < $1.key }) where known[label] != plan {
                            fail("same plan, different pick \(visibleKey), \(label)")
                            break
                        }
                    } else {
                        visibleBehaviour[visibleKey] = picks
                    }
                }
            }
        }
        var firstOfKind: [String: String] = [:]
        var counts: [String: Int] = [:]
        for failure in failures {
            let kind = String(failure.drop { $0 != ":" }.dropFirst(2).prefix { $0 != ":" && $0 != "," })
            counts[kind, default: 0] += 1
            firstOfKind[kind] = firstOfKind[kind] ?? failure
        }
        let report = counts.sorted { $0.key < $1.key }.map { "\($0.value)x \(firstOfKind[$0.key]!)" }
        let message = "\(failures.count) failures\n" + report.joined(separator: "\n")
        #expect(failures.isEmpty, Comment(rawValue: message))
    }
}
