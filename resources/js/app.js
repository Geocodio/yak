import { promptEditor, promptDiff } from "./prompt-editor.js";
import { activityFollow } from "./activity-follow.js";

document.addEventListener("alpine:init", () => {
    window.Alpine.data("promptEditor", promptEditor);
    window.Alpine.data("promptDiff", promptDiff);
    window.Alpine.data("activityFollow", activityFollow);
});
