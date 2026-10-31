import { test } from "node:test";
import assert from "node:assert/strict";
import {
    createBusyTracker,
    networkErrorToast,
} from "../../resources/js/navigation-feedback.mjs";

function harness() {
    const timers = new Map();
    let next = 0;
    const states = [];
    const tracker = createBusyTracker((busy) => states.push(busy), 150, {
        schedule: (fn) => (timers.set(++next, fn), next),
        cancel: (id) => timers.delete(id),
    });
    return { tracker, states, fire: () => timers.forEach((fn) => fn()), timers };
}

test("fast visits never flip the busy state", () => {
    const { tracker, states, fire, timers } = harness();
    tracker.start();
    tracker.finish();
    fire();
    assert.deepEqual(states, []);
    assert.equal(timers.size, 0);
});

test("slow visits turn busy on after the delay and off on finish", () => {
    const { tracker, states, fire } = harness();
    tracker.start();
    fire();
    assert.deepEqual(states, [true]);
    tracker.finish();
    assert.deepEqual(states, [true, false]);
});

test("concurrent visits stay busy until the last one finishes", () => {
    const { tracker, states, fire } = harness();
    tracker.start();
    tracker.start();
    fire();
    tracker.finish();
    assert.deepEqual(states, [true]);
    tracker.finish();
    assert.deepEqual(states, [true, false]);
});

test("network errors produce an actionable error toast", () => {
    const toast = networkErrorToast();
    assert.equal(toast.color, "error");
    assert.match(toast.description, /connection|reload/i);
});
