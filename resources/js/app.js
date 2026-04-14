import { promptEditor, promptDiff } from "./prompt-editor.js";

document.addEventListener("alpine:init", () => {
    window.Alpine.data("promptEditor", promptEditor);
    window.Alpine.data("promptDiff", promptDiff);
});
