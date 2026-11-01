<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { applyColorMode } from "../color-mode.mjs";
const dark = ref(document.documentElement.classList.contains("dark"));
const sync = () => (dark.value = document.documentElement.classList.contains("dark"));
function toggle() {
    dark.value = !dark.value;
    let storage = null;
    try {
        storage = localStorage;
    } catch {
        // Appearance still works when storage is unavailable.
    }
    applyColorMode(dark.value, document.documentElement, storage);
}
onMounted(() => window.addEventListener("perysee:color-mode", sync));
onBeforeUnmount(() => window.removeEventListener("perysee:color-mode", sync));
</script>
<template>
    <UButton
        color="neutral"
        variant="ghost"
        :icon="dark ? 'i-tabler-sun' : 'i-tabler-moon'"
        :aria-label="dark ? 'Switch to light mode' : 'Switch to dark mode'"
        :aria-pressed="dark"
        @click="toggle"
    />
</template>
