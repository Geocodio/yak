import AVFoundation
import SwiftUI
import UIKit

/// The system camera. The photo comes back as JPEG data and goes through the same path as a picked photo.
struct CameraPicker: UIViewControllerRepresentable {
    let onPhoto: (Data) -> Void
    @Environment(\.dismiss) private var dismiss

    enum Access { case available, denied, unavailable }

    /// No camera on this device, access refused in Settings (or by a profile), or ready to use.
    static var access: Access {
        #if DEBUG
            if UserDefaults.standard.string(forKey: "yakTestCamera") == "denied" { return .denied }
        #endif
        guard UIImagePickerController.isSourceTypeAvailable(.camera) else { return .unavailable }
        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .denied, .restricted: return .denied
        default: return .available
        }
    }

    func makeUIViewController(context: Context) -> UIImagePickerController {
        let picker = UIImagePickerController()
        picker.sourceType = .camera
        picker.delegate = context.coordinator
        return picker
    }

    func updateUIViewController(_ picker: UIImagePickerController, context: Context) {}

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    final class Coordinator: NSObject, UIImagePickerControllerDelegate, UINavigationControllerDelegate {
        private let parent: CameraPicker
        init(_ parent: CameraPicker) { self.parent = parent }

        func imagePickerController(
            _ picker: UIImagePickerController,
            didFinishPickingMediaWithInfo info: [UIImagePickerController.InfoKey: Any]
        ) {
            if let image = info[.originalImage] as? UIImage, let data = image.jpegData(compressionQuality: 0.95) {
                parent.onPhoto(data)
            }
            parent.dismiss()
        }

        func imagePickerControllerDidCancel(_ picker: UIImagePickerController) { parent.dismiss() }
    }
}
