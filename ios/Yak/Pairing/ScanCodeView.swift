import SwiftUI
import VisionKit
import YakKit

/// Scans the QR code on the dashboard. Anything that is not a valid pairing link is ignored and
/// scanning goes on. Without a usable camera it explains why and offers the address route.
struct ScanCodeView: View {
    let onLink: (PairingLink) -> Void
    let onEnterAddress: () -> Void

    @State private var isScannerAvailable =
        DataScannerViewController.isSupported && DataScannerViewController.isAvailable

    var body: some View {
        VStack(spacing: 0) {
            if isScannerAvailable {
                CodeScanner(onLink: onLink, onFailure: { isScannerAvailable = false })
                    .ignoresSafeArea(edges: .top)
                    .accessibilityLabel(Text("scan.cameraLabel"))
                Text("scan.hint")
                    .font(.subheadline)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 24)
                    .padding(.top, 16)
            } else {
                unavailable
            }
            Button("scan.enterAddress", action: onEnterAddress)
                .buttonStyle(SecondaryButtonStyle())
                .padding(24)
        }
        .navigationBarTitleDisplayMode(.inline)
    }

    private var unavailable: some View {
        VStack(spacing: 16) {
            Spacer()
            Image(systemName: "camera.fill")
                .font(.system(size: 40, weight: .light))
                .foregroundStyle(.secondary)
                .accessibilityHidden(true)
            Text("scan.unavailable.title").font(.title2.bold())
            Text("scan.unavailable.body").font(.body).foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Spacer()
        }
        .padding(.horizontal, 24)
        .frame(maxWidth: 560)
        .accessibilityElement(children: .combine)
    }
}

private struct CodeScanner: UIViewControllerRepresentable {
    let onLink: (PairingLink) -> Void
    let onFailure: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onLink: onLink) }

    func makeUIViewController(context: Context) -> DataScannerViewController {
        let scanner = DataScannerViewController(
            recognizedDataTypes: [.barcode(symbologies: [.qr])], qualityLevel: .balanced,
            recognizesMultipleItems: false, isHighFrameRateTrackingEnabled: false, isHighlightingEnabled: true)
        scanner.delegate = context.coordinator
        return scanner
    }

    func updateUIViewController(_ scanner: DataScannerViewController, context: Context) {
        guard !scanner.isScanning else { return }
        do { try scanner.startScanning() } catch { onFailure() }
    }

    static func dismantleUIViewController(_ scanner: DataScannerViewController, coordinator: Coordinator) {
        scanner.stopScanning()
    }

    @MainActor final class Coordinator: NSObject, DataScannerViewControllerDelegate {
        let onLink: (PairingLink) -> Void
        private var hasReported = false

        init(onLink: @escaping (PairingLink) -> Void) { self.onLink = onLink }

        func dataScanner(
            _ scanner: DataScannerViewController, didAdd items: [RecognizedItem], allItems: [RecognizedItem]
        ) {
            report(items)
        }

        func dataScanner(
            _ scanner: DataScannerViewController, didUpdate items: [RecognizedItem], allItems: [RecognizedItem]
        ) {
            report(items)
        }

        private func report(_ items: [RecognizedItem]) {
            guard !hasReported else { return }
            for case .barcode(let barcode) in items {
                guard let payload = barcode.payloadStringValue, let url = URL(string: payload),
                    let link = PairingLink(url: url)
                else { continue }
                hasReported = true
                onLink(link)
                return
            }
        }
    }
}
