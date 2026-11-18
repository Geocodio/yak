import SwiftUI

/// Two slots on either side of the fold, placed by the system. Each side holds its slot's marker and bar, so the
/// host puts the panes where the system put the sides.
@available(iOS 27.1, *)
struct FoldSlots<Primary: View, Secondary: View>: View {
    let primary: Primary
    let secondary: Secondary

    var body: some View {
        ArrangementView {
            primary
        } secondary: {
            secondary
        }
        .arrangementViewStyle(.split)
    }
}
