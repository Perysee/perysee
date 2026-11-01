// The real button reads the document when it is created, and there is no document in this run.
import { h } from "vue";

export default { render: () => h("button", { type: "button", "aria-label": "Switch color mode" }) };
