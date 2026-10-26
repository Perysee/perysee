<script setup>
import { ref } from "vue";
const dark = ref(document.documentElement.classList.contains("dark"));
function toggle() {
    dark.value = !dark.value;
    document.documentElement.classList.toggle("dark", dark.value);
    try {
        localStorage.setItem("perysee-color-mode", dark.value ? "dark" : "light");
    } catch { /* Appearance still works when storage is unavailable. */ }
}
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
