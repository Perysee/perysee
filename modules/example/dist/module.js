// The browser side of the Example module. It is plain JavaScript that reaches Vue and Inertia
// through the `Perysee` global, so it needs no build step on the server.
(function () {
    const { h, ref, computed, resolveComponent } = Perysee.Vue;
    const { router } = Perysee.Inertia;

    // A block under the dashboard. It appears only where the module is active, and the module can be
    // limited to some sites from its settings page.
    const Greeting = {
        name: "ExampleGreeting",
        inheritAttrs: false,
        props: ["modules"],
        setup(props) {
            const greeting = computed(() => props.modules?.data?.example?.greeting);
            return () =>
                greeting.value
                    ? h(resolveComponent("UCard"), { class: "mt-6" }, {
                          default: () => h("p", { class: "text-sm" }, greeting.value),
                      })
                    : null;
        },
    };

    // The module's settings page, under Settings > Modules > Example.
    const Settings = {
        name: "ExampleSettings",
        inheritAttrs: false,
        props: ["modules", "errors"],
        setup(props) {
            const data = computed(() => props.modules?.data?.example);
            const text = ref(data.value?.greeting ?? "");
            const busy = ref(false);
            const save = (event) => {
                event?.preventDefault();
                busy.value = true;
                router.post(data.value.save, { greeting: text.value }, { preserveScroll: true, onFinish: () => (busy.value = false) });
            };

            return () => {
                // Members have no save address: show nothing instead of a form that would be refused.
                if (!data.value?.save) {
                    return null;
                }

                return h(resolveComponent("UCard"), { class: "mt-6" }, {
                    default: () => [
                        h("h2", { class: "text-base font-medium" }, "Greeting"),
                        h("p", { class: "text-sm text-muted mt-1 mb-5" }, "Shown under the dashboard to everyone."),
                        h("form", { class: "space-y-5", onSubmit: save }, [
                            h(resolveComponent("UFormField"), { label: "Message", error: props.errors?.greeting }, {
                                default: () => h(resolveComponent("UInput"), {
                                    modelValue: text.value,
                                    "onUpdate:modelValue": (next) => (text.value = next),
                                    maxlength: 80,
                                    class: "w-full max-w-md",
                                }),
                            }),
                            h(resolveComponent("UButton"), { type: "submit", label: "Save", loading: busy.value }),
                        ]),
                    ],
                });
            };
        },
    };

    Perysee.registerSlot("dashboard.after", Greeting, { module: "example" });
    Perysee.registerSettings("example", Settings);
})();
