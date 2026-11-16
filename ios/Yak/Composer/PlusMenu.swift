import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

/// The plus button and everything behind it: the photo library, the camera, the file importer and the model picker.
struct PlusMenu: View {
    let composer: ComposerModel
    @State private var isPickingPhotos = false
    @State private var isPickingFiles = false
    @State private var isTakingPhoto = false
    @State private var isChoosingModel = false
    @State private var pickedPhotos: [PhotosPickerItem] = []

    var body: some View {
        Menu {
            Button {
                isPickingPhotos = true
            } label: {
                Label("plus.photos", systemImage: "photo.on.rectangle")
            }
            .accessibilityIdentifier("composer.plusMenu.photos")
            Button {
                isTakingPhoto = true
            } label: {
                Label("plus.camera", systemImage: "camera")
                if !CameraPicker.isAvailable { Text("plus.cameraUnavailable") }
            }
            .disabled(!CameraPicker.isAvailable)
            .accessibilityIdentifier("composer.plusMenu.camera")
            Button {
                isPickingFiles = true
            } label: {
                Label("plus.file", systemImage: "doc")
            }
            .accessibilityIdentifier("composer.plusMenu.file")
            Divider()
            Button {
                isChoosingModel = true
            } label: {
                Label("plus.model", systemImage: "cpu")
                if composer.modelFamilies.isEmpty {
                    Text("plus.modelNone")
                } else if let label = composer.modelLabel {
                    Text(verbatim: label)
                }
            }
            .disabled(composer.modelFamilies.isEmpty)
            .accessibilityIdentifier("composer.plusMenu.model")
        } label: {
            Image(systemName: "plus").font(.system(size: 20, weight: .medium)).frame(width: 44, height: 44)
                .contentShape(.circle)
        }
        .tint(.primary)
        .accessibilityLabel("composer.plus.label")
        .accessibilityIdentifier("composer.plus")
        .photosPicker(
            isPresented: $isPickingPhotos, selection: $pickedPhotos,
            maxSelectionCount: max(composer.attachmentSlotsLeft, 1), matching: .images
        )
        .onChange(of: pickedPhotos) { _, items in
            guard !items.isEmpty else { return }
            pickedPhotos = []
            Task { await addPhotos(items) }
        }
        .fileImporter(isPresented: $isPickingFiles, allowedContentTypes: [.item], allowsMultipleSelection: true) {
            result in
            switch result {
            case .success(let urls): Task { for url in urls { await composer.addFile(at: url) } }
            case .failure: composer.reportUnreadable()
            }
        }
        .fullScreenCover(isPresented: $isTakingPhoto) {
            CameraPicker { data in
                Task { await composer.add(data, fileName: "Photo.jpg", contentType: "image/jpeg") }
            }
            .ignoresSafeArea()
        }
        .sheet(isPresented: $isChoosingModel) { ModelPicker(composer: composer) }
    }

    private func addPhotos(_ items: [PhotosPickerItem]) async {
        for item in items {
            guard let data = try? await item.loadTransferable(type: Data.self) else {
                composer.reportUnreadable()
                continue
            }
            let type = item.supportedContentTypes.first { $0.conforms(to: .image) }
            await composer.add(
                data, fileName: "Photo.\(type?.preferredFilenameExtension ?? "jpg")",
                contentType: type?.preferredMIMEType ?? "image/jpeg")
        }
    }
}
