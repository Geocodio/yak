import SwiftUI
import YakKit

/// First run: pair this device with an instance.
struct ConnectView: View {
    let model: AppModel
    /// Set when scanning is available (added with the scan screen); without it only the address
    /// route is offered and it becomes the primary button.
    var onScan: (() -> Void)?
    var onDone: (() -> Void)?

    @State private var showsAddress = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                Image(systemName: DeviceDescription.kindName == "iPad" ? "ipad" : "iphone")
                    .font(.system(size: 44, weight: .light))
                    .foregroundStyle(.secondary)
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 12) {
                    Text("connect.title").font(.largeTitle.bold())
                    Text("connect.body \(DeviceDescription.kindName)")
                        .font(.body).foregroundStyle(.secondary)
                }
                VStack(alignment: .leading, spacing: 16) {
                    hint(number: 1, title: "connect.hint1.title", detail: "connect.hint1.detail")
                    hint(number: 2, title: "connect.hint2.title", detail: "connect.hint2.detail")
                }
            }
            .frame(maxWidth: 560, alignment: .leading)
            .frame(maxWidth: .infinity)
            .padding(24)
        }
        .safeAreaInset(edge: .bottom) { buttons }
        .navigationDestination(isPresented: $showsAddress) {
            EnterAddressView(model: model, onDone: onDone)
        }
    }

    private var buttons: some View {
        VStack(spacing: 12) {
            if let onScan {
                Button("connect.scan", action: onScan).buttonStyle(PrimaryButtonStyle())
                Button("connect.enterAddress") { showsAddress = true }.buttonStyle(SecondaryButtonStyle())
            } else {
                Button("connect.enterAddress") { showsAddress = true }.buttonStyle(PrimaryButtonStyle())
            }
        }
        .frame(maxWidth: 560)
        .padding(.horizontal, 24)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity)
        .background(.bar)
    }

    private func hint(number: Int, title: LocalizedStringKey, detail: LocalizedStringKey) -> some View {
        HStack(alignment: .top, spacing: 14) {
            Text("\(number)")
                .font(.subheadline.bold())
                .frame(width: 28, height: 28)
                .background(.quaternary, in: .circle)
                .accessibilityHidden(true)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.headline)
                Text(detail).font(.subheadline).foregroundStyle(.secondary)
            }
        }
        .accessibilityElement(children: .combine)
    }
}
