import Observation
import SwiftUI
import UIKit
import YakKit

/// What the system says about the iPhone Duo's hinge, the fold's region and the keyboard. Devices and systems without
/// a hinge leave it flat. The layout reads `posture`, `fold` and `isKeyboardOnLowerHalf` and never the angle.
@MainActor @Observable
final class HingeState {
    private(set) var posture: HingePosture = .flat
    private(set) var fold: FoldAxis?
    /// The last active `.division` frame in screen coordinates, kept while the region is inactive.
    private(set) var lastDivision: CGRect?
    private(set) var keyboardFrame: CGRect = .zero

    /// The keyboard covers the last known division: typing in the laptop pose.
    var isKeyboardOnLowerHalf: Bool {
        guard posture == .partlyOpen, let lastDivision, !keyboardFrame.isEmpty else { return false }
        return keyboardFrame.intersects(lastDivision)
    }

    func setPosture(_ posture: HingePosture) {
        if self.posture != posture { self.posture = posture }
    }

    /// An active region sets the fold and is remembered; an inactive one clears the fold only.
    func setDivision(_ frame: CGRect?, isActive: Bool) {
        guard isActive, let frame else {
            fold = nil
            return
        }
        fold = FoldAxis(regionWidth: Double(frame.width), regionHeight: Double(frame.height))
        lastDivision = frame
    }

    func setKeyboard(_ frame: CGRect) { keyboardFrame = frame }
}

extension View {
    /// iOS 27.1: `onHingeChange` and the `.division` reserved region; earlier systems and other devices leave the
    /// state flat. In Debug, `-yakTestPosture book|laptop` fakes a partly open hinge.
    func readsHinge(into state: HingeState) -> some View { modifier(HingeReading(state: state)) }
}

private struct HingeReading: ViewModifier {
    let state: HingeState

    func body(content: Content) -> some View {
        content
            .background { Probe(state: state) }
            .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillChangeFrameNotification)) {
                guard let end = $0.userInfo?[UIResponder.keyboardFrameEndUserInfoKey] as? CGRect else { return }
                state.setKeyboard(end)
            }
            .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in
                state.setKeyboard(.zero)
            }
    }

    private struct Probe: View {
        let state: HingeState

        var body: some View {
            GeometryReader { proxy in
                Color.clear
                    .onChange(of: proxy.size, initial: true) { read(proxy) }
                    .modifier(HingeChanges(state: state))
            }
            .allowsHitTesting(false)
        }

        private func read(_ proxy: GeometryProxy) {
            #if DEBUG
                if let posture = TestMode.value("yakTestPosture"), ["book", "laptop"].contains(posture) {
                    // A 40-point band across the middle of the detail stands in for the fold.
                    let origin = proxy.frame(in: .global).origin
                    let size = proxy.size
                    let band =
                        posture == "book"
                        ? CGRect(x: origin.x + (size.width - 40) / 2, y: origin.y, width: 40, height: size.height)
                        : CGRect(x: origin.x, y: origin.y + (size.height - 40) / 2, width: size.width, height: 40)
                    state.setPosture(.partlyOpen)
                    state.setDivision(band, isActive: true)
                    return
                }
            #endif
            if #available(iOS 27.1, *) {
                let region = proxy.reservedRegions(kind: .division, options: [.includeInactive]).first
                let origin = proxy.frame(in: .global).origin
                let frame = region.map { $0.frame.offsetBy(dx: origin.x, dy: origin.y) }
                state.setDivision(frame, isActive: region?.isActive == true)
            }
        }
    }
}

private struct HingeChanges: ViewModifier {
    let state: HingeState

    func body(content: Content) -> some View {
        #if DEBUG
            if TestMode.value("yakTestPosture") != nil { return AnyView(content) }
        #endif
        if #available(iOS 27.1, *) {
            return AnyView(
                content.onHingeChange { _, new in
                    state.setPosture(HingePosture(systemState: new.hinge.map { Self.name($0.status) } ?? "fullyOpen"))
                })
        }
        return AnyView(content)
    }

    @available(iOS 27.1, *)
    private static func name(_ status: DeviceHinge.Status) -> String {
        switch status {
        case .closed: "closed"
        case .partiallyOpen: "partiallyOpen"
        default: "fullyOpen"
        }
    }
}
