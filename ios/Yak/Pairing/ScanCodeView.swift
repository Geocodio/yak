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
                    .font(.yak(.subheadline))
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 24)
                    .padding(.top, 16)
            } else {
                unavailable
            }
            if isScannerAvailable { enterAddressButton }
        }
        .navigationBarTitleDisplayMode(.inline)
    }

    private var enterAddressButton: some View {
        Button("scan.enterAddress", action: onEnterAddress)
            .buttonStyle(SecondaryButtonStyle())
            .padding(24)
    }

    private var unavailable: some View {
        ScrollView {
            VStack(spacing: 0) {
                VStack(spacing: 16) {
                    Image(systemName: "camera.fill")
                        .font(.system(size: 40, weight: .light))
                        .foregroundStyle(Color.yakMuted)
                        .accessibilityHidden(true)
                    Text("scan.unavailable.title").font(.yak(.title2, .bold))
                    Text("scan.unavailable.body").font(.yak(.body)).foregroundStyle(Color.yakMuted)
                }
                .multilineTextAlignment(.center)
                .padding(.horizontal, 24)
                .padding(.top, 80)
                .frame(maxWidth: 560)
                .frame(maxWidth: .infinity)
                .accessibilityElement(children: .combine)
                enterAddressButton.frame(maxWidth: 560)
            }
        }
        .scrollBounceBehavior(.basedOnSize)
    }
}

private struct CodeScanner: UIViewControllerRepresentable {
    let onLink: (PairingLink) -> Void
    let onFailure: () -> Void

    func makeCoordinator() -> Coordinator { Coordinator(onLink: onLink, onFailure: onFailure) }

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
        let onFailure: () -> Void
        private var hasReported = false

        init(onLink: @escaping (PairingLink) -> Void, onFailure: @escaping () -> Void) {
            self.onLink = onLink
            self.onFailure = onFailure
        }

        func dataScanner(
            _ scanner: DataScannerViewController,
            becameUnavailableWithError error: DataScannerViewController.ScanningUnavailable
        ) {
            onFailure()
        }

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
