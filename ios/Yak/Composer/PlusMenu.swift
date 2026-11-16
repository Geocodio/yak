import PhotosUI
import SwiftUI
import UniformTypeIdentifiers

/// The plus button and everything behind it: the photo library, the camera, the file importer and the model picker.
struct PlusMenu: View {
    let composer: ComposerModel
    /// Set when the thread's show allows driving: adds the Add a sub-thread row.
    var onAddSubThread: (@MainActor @Sendable () -> Void)?
    @State private var isPickingPhotos = false
    @State private var isPickingFiles = false
    @State private var isTakingPhoto = false
    @State private var isChoosingModel = false
    @State private var isShowingCameraDenied = false
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
                if CameraPicker.access == .denied { isShowingCameraDenied = true } else { isTakingPhoto = true }
            } label: {
                Label("plus.camera", systemImage: "camera")
                switch CameraPicker.access {
                case .unavailable: Text("plus.cameraUnavailable")
                case .denied: Text("plus.cameraDenied.short")
                case .available: EmptyView()
                }
            }
            .disabled(CameraPicker.access == .unavailable)
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
            if let onAddSubThread {
                Button(action: onAddSubThread) {
                    Label("thread.menu.addSubThread", systemImage: "arrow.triangle.branch")
                }
                .accessibilityIdentifier("composer.plusMenu.addSubThread")
            }
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
            // Send stays off from the pick itself, not from the first item that has loaded.
            composer.reservePicks(items.count)
            Task { await addPhotos(items) }
        }
        .fileImporter(isPresented: $isPickingFiles, allowedContentTypes: [.item], allowsMultipleSelection: true) {
            result in
            switch result {
            case .success(let urls):
                composer.reservePicks(urls.count)
                Task { for url in urls { await composer.addFile(at: url, isReserved: true) } }
            case .failure: composer.reportUnreadable()
            }
        }
        .fullScreenCover(isPresented: $isTakingPhoto) {
            CameraPicker { data in
                composer.reservePicks(1)
                Task { await composer.add(data, fileName: "Photo.jpg", contentType: "image/jpeg", isReserved: true) }
            }
            .ignoresSafeArea()
        }
        .sheet(isPresented: $isChoosingModel) { ModelPicker(composer: composer) }
        .sheet(isPresented: $isShowingCameraDenied) { CameraDeniedSheet() }
    }

    private func addPhotos(_ items: [PhotosPickerItem]) async {
        for item in items {
            guard let data = try? await item.loadTransferable(type: Data.self) else {
                composer.releasePick()
                continue
            }
            let type = item.supportedContentTypes.first { $0.conforms(to: .image) }
            await composer.add(
                data, fileName: "Photo.\(type?.preferredFilenameExtension ?? "jpg")",
                contentType: type?.preferredMIMEType ?? "image/jpeg", isReserved: true)
        }
    }
}

/// What the Camera row opens when access to the camera is off: why, and the way to Settings.
private struct CameraDeniedSheet: View {
    @Environment(\.dismiss) private var dismiss
    @Environment(\.openURL) private var openURL
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 16) {
                    Image(systemName: "camera.fill").font(.system(size: 40)).foregroundStyle(.secondary)
                        .accessibilityHidden(true)
                    Text("plus.cameraDenied.title").font(.title3.weight(.semibold)).multilineTextAlignment(.center)
                    Text("plus.cameraDenied.body").font(.body).foregroundStyle(.primary.opacity(0.85))
                        .multilineTextAlignment(.center)
                    Button("plus.cameraDenied.settings") {
                        if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
                    }
                    .buttonStyle(.bordered).tint(.primary).controlSize(.large)
                    .accessibilityIdentifier("composer.cameraDenied.settings")
                }
                .padding(24).frame(maxWidth: .infinity)
            }
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("plus.cameraDenied.close") { dismiss() }
                }
            }
            .navigationBarTitleDisplayMode(.inline)
        }
        .presentationDetents(dynamicTypeSize.isAccessibilitySize ? [.large] : [.medium])
        .presentationBackground(Color(.systemBackground))
        .accessibilityIdentifier("composer.cameraDenied")
    }
}
