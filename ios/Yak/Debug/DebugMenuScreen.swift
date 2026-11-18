import SwiftUI
import YakKit

/// The Debug menu of Debug and TestFlight builds.
struct DebugMenuScreen: View {
    let model: DebugMenuModel
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        List {
            Section {
                NavigationLink {
                    DebugGalleryScreen()
                } label: {
                    VStack(alignment: .leading, spacing: 2) {
                        Text("debug.gallery.row")
                        Text("debug.gallery.count \(DebugGallery.entries.count)")
                            .font(.footnote.weight(.medium)).foregroundStyle(Color.yakMuted)
                    }
                    .frame(minHeight: 44, alignment: .leading)
                }
                .accessibilityIdentifier("debug.gallery")
                #if DEBUG
                    NavigationLink {
                        LiveActivityGallery(stateName: "working")
                    } label: {
                        Text("debug.liveActivities").frame(minHeight: 44, alignment: .leading)
                    }
                    .accessibilityIdentifier("debug.liveActivities")
                #endif
            } header: {
                Text("debug.states.header").foregroundStyle(Color.yakMuted)
            }
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
                Text("debug.requests.header").foregroundStyle(Color.yakMuted)
            } footer: {
                Text("debug.requests.footer").foregroundStyle(Color.yakMuted).fixedSize(
                    horizontal: false, vertical: true
                )
                .id(dynamicTypeSize)
            }
            Section {
                NavigationLink {
                    LogScreen(model: model)
                } label: {
                    Text("debug.log.row").frame(minHeight: 44, alignment: .leading)
                }
                .accessibilityIdentifier("debug.log")
            } header: {
                Text("debug.log.header").foregroundStyle(Color.yakMuted)
            }
            Section {
                Button {
                    model.showTipsAgain()
                } label: {
                    Text(model.tipsWillShowAgain ? "debug.tips.done" : "debug.tips.row")
                        .frame(maxWidth: .infinity, minHeight: 44, alignment: .leading)
                }
                .disabled(model.tipsWillShowAgain)
                .accessibilityIdentifier("debug.tips")
            } header: {
                Text("debug.tips.header").foregroundStyle(Color.yakMuted)
            } footer: {
                Text("debug.tips.footer").foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true)
                    .id(dynamicTypeSize)
            }
            Section {
            } footer: {
                Text(LocalizedStringKey(model.kind == .testFlight ? "debug.build.testFlight" : "debug.build.debug"))
                    .foregroundStyle(Color.yakMuted).fixedSize(horizontal: false, vertical: true).id(dynamicTypeSize)
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
