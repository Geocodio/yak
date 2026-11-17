import SwiftUI
import YakKit

/// The Debug menu of Debug and TestFlight builds.
struct DebugMenuScreen: View {
    let model: DebugMenuModel

    var body: some View {
        List {
            Section {
                ForEach(DebugFault.allCases, id: \.self) { fault in
                    Button {
                        model.setFault(fault)
                    } label: {
                        HStack {
                            Text(verbatim: name(of: fault)).foregroundStyle(Color.primary)
                            Spacer(minLength: 8)
                            if model.fault == fault {
                                Image(systemName: "checkmark").foregroundStyle(Color.yakAction)
                                    .accessibilityHidden(true)
                            }
                        }
                        .frame(minHeight: 44)
                        .contentShape(.rect)
                    }
                    .accessibilityAddTraits(model.fault == fault ? .isSelected : [])
                    .accessibilityIdentifier("debug.fault.\(fault.rawValue)")
                }
            } header: {
                Text("debug.requests.header")
            } footer: {
                Text("debug.requests.footer")
            }
            Section {
            } footer: {
                Text(LocalizedStringKey(model.kind == .testFlight ? "debug.build.testFlight" : "debug.build.debug"))
            }
        }
        .navigationTitle("about.debug")
        .navigationBarTitleDisplayMode(.inline)
        .accessibilityIdentifier("debug.menu")
    }

    private func name(of fault: DebugFault) -> String {
        switch fault {
        case .off: String(localized: "debug.fault.off")
        case .slow: String(localized: "debug.fault.slow")
        case .verySlow: String(localized: "debug.fault.verySlow")
        case .offline: String(localized: "debug.fault.offline")
        case .serverError: String(localized: "debug.fault.serverError")
        }
    }
}
