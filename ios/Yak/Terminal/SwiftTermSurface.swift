import SwiftTerm
import UIKit

/// The terminal surface drawn by the emulator package. It is the only file that imports the package.
@MainActor
final class SwiftTermSurface: NSObject, TerminalSurface, TerminalViewDelegate {
    private let terminalView: TerminalView
    private var fontSize = TerminalFontSize.baseSize
    private var pinchStartSize: CGFloat = 0
    private var wasAtEnd = true

    var onInput: (([UInt8]) -> Void)?
    var onSizeChange: ((_ columns: Int, _ rows: Int) -> Void)?
    var onScrollPositionChange: ((_ isAtEnd: Bool) -> Void)?

    /// 5000 lines of scrollback per shell.
    static let scrollbackLines = 5000

    override init() {
        let options = TerminalOptions(scrollback: Self.scrollbackLines)
        terminalView = TerminalView(
            frame: .zero, font: UIFont.monospacedSystemFont(ofSize: TerminalFontSize.baseSize, weight: .regular),
            options: options)
        super.init()
        terminalView.terminalDelegate = self
        terminalView.inputAccessoryView = nil
        terminalView.optionAsMetaKey = true
        Self.install(Palette.terminal, on: terminalView)
        terminalView.isAccessibilityElement = true
        terminalView.accessibilityLabel = String(localized: "terminal.output.label")
        terminalView.accessibilityIdentifier = "terminal.view"
        let pinch = UIPinchGestureRecognizer(target: self, action: #selector(pinched(_:)))
        terminalView.addGestureRecognizer(pinch)
        terminalView.accessibilityValue = ""
    }

    var view: UIView { terminalView }

    var isApplicationCursorMode: Bool {
        terminalView.terminalContentSnapshot(region: .viewport)?.inputState.applicationCursor ?? false
    }

    var visibleText: String {
        guard let snapshot = terminalView.terminalContentSnapshot(region: .viewport) else { return "" }
        return snapshot.rows.map(\.text).joined(separator: "\n")
    }

    var isScrolledToEnd: Bool {
        let bottom =
            terminalView.contentSize.height - terminalView.bounds.height + terminalView.adjustedContentInset.bottom
        return terminalView.contentOffset.y >= bottom - 2
    }

    func feed(_ bytes: Data) {
        terminalView.feed(byteArray: [UInt8](bytes)[...])
        terminalView.accessibilityValue = visibleText
    }

    func feedDivider(_ text: String) {
        terminalView.feed(text: "\r\n\u{1B}[2;90m\(text)\u{1B}[0m\r\n")
        terminalView.accessibilityValue = visibleText
    }

    func scrollToEnd() {
        let bottom =
            terminalView.contentSize.height - terminalView.bounds.height + terminalView.adjustedContentInset.bottom
        terminalView.setContentOffset(CGPoint(x: 0, y: max(0, bottom)), animated: false)
    }

    func setFontSize(_ points: CGFloat) {
        let size = min(max(points, TerminalFontSize.range.lowerBound), TerminalFontSize.range.upperBound)
        guard size != fontSize else { return }
        fontSize = size
        terminalView.font = UIFont.monospacedSystemFont(ofSize: size, weight: .regular)
    }

    func focus() { _ = terminalView.becomeFirstResponder() }
    func resignFocus() { _ = terminalView.resignFirstResponder() }

    @objc private func pinched(_ recognizer: UIPinchGestureRecognizer) {
        switch recognizer.state {
        case .began: pinchStartSize = fontSize
        case .changed: setFontSize((pinchStartSize * recognizer.scale).rounded())
        default: break
        }
    }

    // MARK: TerminalViewDelegate

    nonisolated func sizeChanged(source: TerminalView, newCols: Int, newRows: Int) {
        MainActor.assumeIsolated { onSizeChange?(newCols, newRows) }
    }

    nonisolated func setTerminalTitle(source: TerminalView, title: String) {}
    nonisolated func hostCurrentDirectoryUpdate(source: TerminalView, directory: String?) {}

    nonisolated func send(source: TerminalView, data: ArraySlice<UInt8>) {
        let bytes = Array(data)
        MainActor.assumeIsolated { onInput?(bytes) }
    }

    nonisolated func scrolled(source: TerminalView, position: Double) {
        MainActor.assumeIsolated {
            let isAtEnd = isScrolledToEnd
            guard isAtEnd != wasAtEnd else { return }
            wasAtEnd = isAtEnd
            onScrollPositionChange?(isAtEnd)
        }
    }

    nonisolated func requestOpenLink(source: TerminalView, link: String, params: [String: String]) {}
    nonisolated func bell(source: TerminalView) {}

    nonisolated func clipboardCopy(source: TerminalView, content: Data) {
        MainActor.assumeIsolated { UIPasteboard.general.string = String(data: content, encoding: .utf8) }
    }

    nonisolated func iTermContent(source: TerminalView, content: ArraySlice<UInt8>) {}
    nonisolated func rangeChanged(source: TerminalView, startY: Int, endY: Int) {}
}

/// The terminal's colors: a near-black background in both appearances and 16 ANSI colors tuned for contrast on it.
private enum Palette {
    static let terminal: [(red: UInt8, green: UInt8, blue: UInt8)] = [
        (0x1C, 0x1C, 0x1E), (0xFF, 0x6B, 0x62), (0x6D, 0xD4, 0x7E), (0xF2, 0xC9, 0x4C),
        (0x6C, 0xA8, 0xFF), (0xD8, 0x8B, 0xFF), (0x5F, 0xD7, 0xD7), (0xE5, 0xE5, 0xE7),
        (0x8E, 0x8E, 0x93), (0xFF, 0x8F, 0x88), (0x8F, 0xE8, 0x9E), (0xFF, 0xDD, 0x7A),
        (0x9A, 0xC4, 0xFF), (0xE6, 0xAE, 0xFF), (0x8A, 0xEA, 0xEA), (0xFF, 0xFF, 0xFF),
    ]
    static let background = UIColor(red: 0.07, green: 0.07, blue: 0.08, alpha: 1)
    static let foreground = UIColor(red: 0.90, green: 0.90, blue: 0.91, alpha: 1)
}

extension SwiftTermSurface {
    fileprivate static func install(_ palette: [(red: UInt8, green: UInt8, blue: UInt8)], on view: TerminalView) {
        view.installColors(
            palette.map { Color(red8: UInt16($0.red), green8: UInt16($0.green), blue8: UInt16($0.blue)) })
        view.nativeBackgroundColor = Palette.background
        view.nativeForegroundColor = Palette.foreground
    }
}
