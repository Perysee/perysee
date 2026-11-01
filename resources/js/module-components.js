// Nuxt UI components that modules can use. Nuxt UI imports components at compile time, so they are not
// registered globally: a module's compiled script could not find them with resolveComponent("UCard").
// The application registers this list once, and the module guide tells authors to stay within it.
import UAlert from "@nuxt/ui/components/Alert.vue";
import UBadge from "@nuxt/ui/components/Badge.vue";
import UButton from "@nuxt/ui/components/Button.vue";
import UCard from "@nuxt/ui/components/Card.vue";
import UCheckbox from "@nuxt/ui/components/Checkbox.vue";
import UColorPicker from "@nuxt/ui/components/ColorPicker.vue";
import UEmpty from "@nuxt/ui/components/Empty.vue";
import UFormField from "@nuxt/ui/components/FormField.vue";
import UIcon from "@nuxt/ui/components/Icon.vue";
import UInput from "@nuxt/ui/components/Input.vue";
import UPopover from "@nuxt/ui/components/Popover.vue";
import USelect from "@nuxt/ui/components/Select.vue";
import USeparator from "@nuxt/ui/components/Separator.vue";
import USwitch from "@nuxt/ui/components/Switch.vue";
import UTextarea from "@nuxt/ui/components/Textarea.vue";

export const moduleComponents = {
    UAlert,
    UBadge,
    UButton,
    UCard,
    UCheckbox,
    UColorPicker,
    UEmpty,
    UFormField,
    UIcon,
    UInput,
    UPopover,
    USelect,
    USeparator,
    USwitch,
    UTextarea,
};

/**
 * Registers the components above on the Vue application so a module can reach them with resolveComponent.
 */
export function registerModuleComponents(app) {
    for (const [name, component] of Object.entries(moduleComponents)) {
        app.component(name, component);
    }

    return app;
}
