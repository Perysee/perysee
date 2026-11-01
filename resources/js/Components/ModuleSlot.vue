<script>
export default { inheritAttrs: false };
</script>
<script setup>
import { computed, useAttrs } from "vue";
import { moduleHost } from "../module-runtime.js";
import ModuleSlotBoundary from "./ModuleSlotBoundary.vue";
const props = defineProps({ name: { type: String, required: true } });
const attrs = useAttrs();
// Only components of modules that are active on this page appear: a module limited to some sites vanishes elsewhere.
const components = computed(() => moduleHost.visibleSlot(props.name, attrs.modules?.active ?? []));
</script>
<template>
    <ModuleSlotBoundary v-for="(component, index) in components" :key="`${name}:${index}`">
        <component :is="component" v-bind="$attrs" />
    </ModuleSlotBoundary>
</template>
