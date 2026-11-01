<script setup>
import { onErrorCaptured, ref } from "vue";
const failed = ref(false);
onErrorCaptured((error) => {
    console.error("A module component failed to render.", error);
    failed.value = true;
    return false;
});
</script>
<template>
    <UAlert
        v-if="failed"
        color="warning"
        variant="soft"
        icon="i-tabler-puzzle-off"
        title="A module section could not be displayed"
        description="Another module component failed. The rest of this page is unaffected."
        class="mt-6"
    />
    <slot v-else />
</template>
