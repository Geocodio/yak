import SwiftUI
import YakKit

/// First run: pair this device with an instance.
struct ConnectView: View {
    let model: AppModel
    var onDone: (() -> Void)?

    @State private var showsAddress = false
    @State private var showsScan = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                Image(systemName: "qrcode.viewfinder")
                    .font(.system(size: 44, weight: .light))
                    .foregroundStyle(Color.yakMuted)
                    .accessibilityHidden(true)
                VStack(alignment: .leading, spacing: 12) {
                    Text("connect.title").font(.largeTitle.bold())
                    Text("connect.body")
                        .font(.body).foregroundStyle(Color.yakMuted)
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
        .onChange(of: model.wantsScanner, initial: true) {
            guard model.wantsScanner else { return }
            model.wantsScanner = false
            showsScan = true
        }
        .navigationDestination(isPresented: $showsScan) {
            ScanCodeView(
                onLink: { link in
                    // Back to the connect screen, so a failed pairing leaves a scanner that works again.
                    showsScan = false
                    Task { await model.pair(link: link) }
                },
                onEnterAddress: {
                    showsScan = false
                    showsAddress = true
                })
        }
        .navigationDestination(isPresented: $showsAddress) {
            EnterAddressView(model: model, onDone: onDone)
        }
    }

    private var buttons: some View {
        VStack(spacing: 12) {
            Button("connect.scan") { showsScan = true }.buttonStyle(PrimaryButtonStyle())
            Button("connect.enterAddress") { showsAddress = true }.buttonStyle(SecondaryButtonStyle())
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
                Text(detail).font(.subheadline).foregroundStyle(Color.yakMuted)
            }
        }
        .accessibilityElement(children: .combine)
    }
}
