// A stand-in for @inertiajs/vue3 in the module UI test, which has no Inertia application to provide a page.
import { h, reactive } from "vue";

const page = reactive({ component: "Workspace", url: "/settings/modules/white-label", props: {}, version: null });

export const usePage = () => page;
export const router = { post() {}, delete() {}, visit() {}, on: () => () => {} };
export const Link = {
    props: ["href"],
    inheritAttrs: false,
    render() {
        return h("a", { ...this.$attrs, href: this.href }, this.$slots.default?.());
    },
};
export const useForm = (data) => reactive({ ...data, post() {}, processing: false, errors: {} });
