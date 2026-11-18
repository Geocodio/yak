import CoreGraphics
import Observation
import YakKit

/// Which panes are alive, which slot each shown pane sits in, and the frame of every slot. A pane that moves
/// between slots keeps its place in `alive`, so its view is never rebuilt.
@MainActor @Observable
final class PaneSlotsModel {
    /// Every pane opened in this thread, in opening order. It never shrinks until the thread closes.
    private(set) var alive: [PaneID] = []
    /// Reported by the slot markers in the "thread.detail" space.
    private(set) var frames: [SlotPosition: CGRect] = [:]
    private(set) var placement: [PaneID: SlotPosition] = [:]
    private(set) var arrangement: PaneArrangement = .single

    /// Adds the newly shown panes to `alive` and places each shown pane in its slot.
    func show(_ plan: SlotPlan) {
        arrangement = plan.arrangement
        var next: [PaneID: SlotPosition] = [:]
        for slot in plan.slots {
            guard case .pane(let pane) = slot.content else { continue }
            next[pane] = slot.position
            if !alive.contains(pane) { alive.append(pane) }
        }
        if placement != next { placement = next }
    }

    /// A slot's frame. A frame that is not finite is ignored; a frame of a slot no pane sits in is never read.
    func report(_ frame: CGRect, for position: SlotPosition) {
        guard frame.origin.x.isFinite, frame.origin.y.isFinite, frame.width.isFinite, frame.height.isFinite
        else { return }
        if frames[position] != frame { frames[position] = frame }
    }

    /// The slot's pane becomes hidden: alive, without a frame, until the next `show`.
    func clear(_ position: SlotPosition) {
        placement = placement.filter { $0.value != position }
    }

    /// The pane's frame; nil when it is hidden, or when its slot has not reported yet.
    func frame(for pane: PaneID) -> CGRect? {
        placement[pane].flatMap { frames[$0] }
    }

    func isShown(_ pane: PaneID) -> Bool { placement[pane] != nil }

    func threadClosed() {
        alive = []
        frames = [:]
        placement = [:]
    }
}
