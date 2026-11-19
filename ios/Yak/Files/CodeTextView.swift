import SwiftUI
import UIKit
import YakKit

/// What a colored span of code looks like, in both appearances.
enum CodeColors {
    static func color(for kind: SyntaxKind) -> UIColor {
        switch kind {
        case .keyword: dynamic(light: (0.61, 0.14, 0.58), dark: (0.99, 0.37, 0.64))
        case .string: dynamic(light: (0.69, 0.10, 0.09), dark: (0.99, 0.46, 0.40))
        case .comment: dynamic(light: (0.36, 0.42, 0.47), dark: (0.62, 0.68, 0.73))
        case .number: dynamic(light: (0.11, 0.0, 0.81), dark: (0.82, 0.75, 0.41))
        }
    }

    /// The tint of a changed line in the conflict compare.
    static let changedLine = dynamic(light: (1.0, 0.9, 0.7), dark: (0.35, 0.27, 0.10))

    private static func dynamic(light: (CGFloat, CGFloat, CGFloat), dark: (CGFloat, CGFloat, CGFloat)) -> UIColor {
        UIColor { traits in
            let value = traits.userInterfaceStyle == .dark ? dark : light
            return UIColor(red: value.0, green: value.1, blue: value.2, alpha: 1)
        }
    }
}

/// One UITextView wrapper for the viewer (read-only, selectable) and the editor (editable): a monospaced Dynamic Type
/// font, a line-number gutter, no wrapping unless asked, and an input accessory view for the code key row.
struct CodeTextView: UIViewRepresentable {
    let text: String
    var tokens: [SyntaxToken] = []
    var isEditable = false
    var wrapsLines = false
    /// Zero-based lines that get a tinted background.
    var tintedLines: Set<Int> = []
    var showsKeyRow = false
    var accessibilityIdentifier = "files.viewer"
    var accessibilityLabel: LocalizedStringResource = "files.viewer.label"
    var onChange: ((String) -> Void)?

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> CodeUITextView {
        let view = CodeUITextView()
        view.delegate = context.coordinator
        view.backgroundColor = UIColor(Color.yakBackground)
        view.alwaysBounceVertical = true
        view.autocorrectionType = .no
        view.autocapitalizationType = .none
        view.smartQuotesType = .no
        view.smartDashesType = .no
        view.smartInsertDeleteType = .no
        view.spellCheckingType = .no
        view.inlinePredictionType = .no
        view.keyboardDismissMode = .interactive
        view.isAccessibilityElement = true
        return view
    }

    func updateUIView(_ view: CodeUITextView, context: Context) {
        let coordinator = context.coordinator
        coordinator.parent = self
        view.accessibilityIdentifier = accessibilityIdentifier
        view.accessibilityLabel = String(localized: accessibilityLabel)
        view.isEditable = isEditable
        view.isSelectable = true
        view.tintColor = .tintColor
        let category = view.traitCollection.preferredContentSizeCategory
        let isDifferent = coordinator.rendered?.matches(self, category: category) != true
        if isDifferent {
            view.display(
                text: text, tokens: tokens, tintedLines: tintedLines, wrapsLines: wrapsLines, isEditing: isEditable,
                keepsSelection: coordinator.rendered?.text == text)
            coordinator.rendered = Rendered(
                text: text, tokens: tokens, tintedLines: tintedLines, wrapsLines: wrapsLines, category: category,
                isEditable: isEditable)
        }
        coordinator.setKeyRow(visible: showsKeyRow && isEditable, on: view)
    }

    struct Rendered {
        let text: String
        let tokens: [SyntaxToken]
        let tintedLines: Set<Int>
        let wrapsLines: Bool
        let category: UIContentSizeCategory
        let isEditable: Bool

        func matches(_ view: CodeTextView, category: UIContentSizeCategory) -> Bool {
            text == view.text && tokens == view.tokens && tintedLines == view.tintedLines
                && wrapsLines == view.wrapsLines && self.category == category && isEditable == view.isEditable
        }
    }

    @MainActor final class Coordinator: NSObject, UITextViewDelegate {
        var parent: CodeTextView?
        var rendered: Rendered?
        private var keyRow: UIHostingController<CodeKeyRow>?

        func textViewDidChange(_ textView: UITextView) {
            guard let view = textView as? CodeUITextView else { return }
            view.textChanged()
            rendered = rendered.map {
                Rendered(
                    text: textView.text, tokens: [], tintedLines: [], wrapsLines: $0.wrapsLines, category: $0.category,
                    isEditable: $0.isEditable)
            }
            parent?.onChange?(textView.text)
        }

        func setKeyRow(visible: Bool, on view: CodeUITextView) {
            guard visible else {
                if view.inputAccessoryView != nil {
                    view.inputAccessoryView = nil
                    view.reloadInputViews()
                }
                return
            }
            guard keyRow == nil else { return }
            let host = UIHostingController(
                rootView: CodeKeyRow(
                    insert: { [weak view] text in view?.insertText(text) },
                    indentation: { [weak view] in
                        view?.indentation ?? "\t"
                    }))
            host.sizingOptions = [.intrinsicContentSize]
            let container = UIInputView(frame: CGRect(x: 0, y: 0, width: 320, height: 52), inputViewStyle: .keyboard)
            container.allowsSelfSizing = true
            host.view.backgroundColor = .clear
            host.view.translatesAutoresizingMaskIntoConstraints = false
            container.translatesAutoresizingMaskIntoConstraints = false
            container.addSubview(host.view)
            NSLayoutConstraint.activate([
                host.view.leadingAnchor.constraint(equalTo: container.leadingAnchor),
                host.view.trailingAnchor.constraint(equalTo: container.trailingAnchor),
                host.view.topAnchor.constraint(equalTo: container.topAnchor),
                host.view.bottomAnchor.constraint(equalTo: container.bottomAnchor),
                container.heightAnchor.constraint(equalToConstant: 52),
            ])
            keyRow = host
            view.inputAccessoryView = container
            view.reloadInputViews()
        }
    }
}

/// A text view with TextKit 1 and a gutter of line numbers pinned to its left edge.
final class CodeUITextView: UITextView {
    private let gutter = GutterView()
    private var lineStarts = [0]
    private var wraps = false
    private var isEditing = false
    private static let baseSize: CGFloat = 14

    init() {
        let storage = NSTextStorage()
        let layout = NSLayoutManager()
        let container = NSTextContainer(size: CGSize(width: 0, height: CGFloat.greatestFiniteMagnitude))
        container.widthTracksTextView = true
        layout.addTextContainer(container)
        storage.addLayoutManager(layout)
        super.init(frame: .zero, textContainer: container)
        textContainer.lineFragmentPadding = 4
        adjustsFontForContentSizeCategory = true
        addSubview(gutter)
        gutter.owner = self
        gutter.isUserInteractionEnabled = false
        gutter.isAccessibilityElement = false
        gutter.accessibilityElementsHidden = true
        registerForTraitChanges([UITraitUserInterfaceStyle.self]) { (view: CodeUITextView, _) in
            view.gutter.setNeedsDisplay()
        }
    }

    @available(*, unavailable) required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

    var codeFont: UIFont {
        UIFontMetrics(forTextStyle: .body).scaledFont(
            for: .monospacedSystemFont(ofSize: Self.baseSize, weight: .regular),
            compatibleWith: traitCollection)
    }

    /// The first indented line decides: spaces give four spaces, anything else a tab.
    var indentation: String {
        for line in text.split(separator: "\n", omittingEmptySubsequences: true) {
            if line.hasPrefix("\t") { return "\t" }
            if line.hasPrefix("  ") { return "    " }
        }
        return "\t"
    }

    func display(
        text: String, tokens: [SyntaxToken], tintedLines: Set<Int>, wrapsLines: Bool, isEditing: Bool,
        keepsSelection: Bool
    ) {
        wraps = wrapsLines
        self.isEditing = isEditing
        let font = codeFont
        let attributed = NSMutableAttributedString(
            string: text, attributes: [.font: font, .foregroundColor: UIColor.label])
        let length = attributed.length
        for token in tokens where token.range.upperBound <= length {
            attributed.addAttribute(
                .foregroundColor, value: CodeColors.color(for: token.kind),
                range: NSRange(location: token.range.lowerBound, length: token.range.count))
        }
        if !tintedLines.isEmpty {
            let source = text as NSString
            var line = 0
            var location = 0
            while location <= source.length {
                let range = source.lineRange(for: NSRange(location: min(location, source.length), length: 0))
                if tintedLines.contains(line), range.length > 0 {
                    attributed.addAttribute(.backgroundColor, value: CodeColors.changedLine, range: range)
                }
                line += 1
                location = NSMaxRange(range)
                if range.length == 0 { break }
            }
        }
        let selected = selectedRange
        attributedText = attributed
        typingAttributes = [.font: font, .foregroundColor: UIColor.label]
        if keepsSelection, NSMaxRange(selected) <= length { selectedRange = selected }
        textChanged()
    }

    func textChanged() {
        var starts = [0]
        let units = text.utf16
        var index = 0
        for unit in units {
            index += 1
            if unit == 10 { starts.append(index) }
        }
        lineStarts = starts
        updateGeometry()
        gutter.setNeedsDisplay()
    }

    private var gutterWidth: CGFloat {
        let digits = max(2, String(lineStarts.count).count)
        let sample = String(repeating: "8", count: digits) as NSString
        return ceil(sample.size(withAttributes: [.font: codeFont]).width) + 14
    }

    private func updateGeometry() {
        let left = gutterWidth
        textContainerInset = UIEdgeInsets(top: 8, left: left, bottom: 8, right: 8)
        if wraps {
            textContainer.widthTracksTextView = true
            textContainer.lineBreakMode = .byWordWrapping
        } else {
            textContainer.widthTracksTextView = false
            textContainer.lineBreakMode = .byWordWrapping
            let advance = ("8" as NSString).size(withAttributes: [.font: codeFont]).width
            let longest = text.split(separator: "\n", omittingEmptySubsequences: false).map(\.count).max() ?? 0
            let wanted = CGFloat(longest) * advance + 40
            let available = max(bounds.width - left - 8, 0)
            desiredContainerWidth = min(max(wanted, available), 40_000)
            textContainer.size = CGSize(width: desiredContainerWidth, height: .greatestFiniteMagnitude)
            relayout()
        }
    }

    private var laidOutWidth: CGFloat = 0

    /// A new container size leaves the old line breaks in place until the layout is thrown away.
    private func relayout() {
        let whole = NSRange(location: 0, length: textStorage.length)
        layoutManager.invalidateLayout(forCharacterRange: whole, actualCharacterRange: nil)
        layoutManager.ensureLayout(for: textContainer)
    }
    private var desiredContainerWidth: CGFloat = 0

    override func layoutSubviews() {
        super.layoutSubviews()
        if bounds.width != laidOutWidth {
            laidOutWidth = bounds.width
            updateGeometry()
        }
        if !wraps, desiredContainerWidth > 0, textContainer.size.width != desiredContainerWidth {
            textContainer.widthTracksTextView = false
            textContainer.lineBreakMode = .byWordWrapping
            textContainer.size = CGSize(width: desiredContainerWidth, height: .greatestFiniteMagnitude)
            relayout()
        }
        gutter.frame = CGRect(x: contentOffset.x, y: contentOffset.y, width: gutterWidth, height: bounds.height)
        bringSubviewToFront(gutter)
        gutter.setNeedsDisplay()
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()
        updateGeometry()
    }

    fileprivate func lineNumber(forCharacter index: Int) -> Int {
        var low = 0
        var high = lineStarts.count - 1
        while low < high {
            let middle = (low + high + 1) / 2
            if lineStarts[middle] <= index { low = middle } else { high = middle - 1 }
        }
        return low + 1
    }

    fileprivate func startsLine(_ index: Int) -> Bool {
        var low = 0
        var high = lineStarts.count - 1
        while low <= high {
            let middle = (low + high) / 2
            if lineStarts[middle] == index { return true }
            if lineStarts[middle] < index { low = middle + 1 } else { high = middle - 1 }
        }
        return false
    }
}

/// Draws the numbers of the lines that are on screen.
private final class GutterView: UIView {
    weak var owner: CodeUITextView?

    override init(frame: CGRect) {
        super.init(frame: frame)
        isOpaque = false
        backgroundColor = UIColor(Color.yakBackground)
        contentMode = .redraw
    }

    @available(*, unavailable) required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

    override func draw(_ rect: CGRect) {
        guard let owner else { return }
        let layout = owner.layoutManager
        let container = owner.textContainer
        let offset = owner.contentOffset.y
        let inset = owner.textContainerInset.top
        let visible = CGRect(x: 0, y: offset - inset, width: container.size.width, height: bounds.height)
        let glyphs = layout.glyphRange(forBoundingRect: visible, in: container)
        let attributes: [NSAttributedString.Key: Any] = [
            .font: owner.codeFont, .foregroundColor: UIColor.label.withAlphaComponent(0.7),
        ]
        layout.enumerateLineFragments(forGlyphRange: glyphs) { fragment, _, _, range, _ in
            let character = layout.characterIndexForGlyph(at: range.location)
            guard owner.startsLine(character) else { return }
            let number = "\(owner.lineNumber(forCharacter: character))" as NSString
            let size = number.size(withAttributes: attributes)
            let y = fragment.minY + inset - offset + (fragment.height - size.height) / 2
            number.draw(at: CGPoint(x: self.bounds.width - size.width - 8, y: y), withAttributes: attributes)
        }
    }
}
