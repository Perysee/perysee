// White label: the settings page shown under Settings > Modules > White label.
// It reaches Vue and Inertia through the globals the Perysee host provides.
(function () {
    const { h, reactive, ref, computed, watch, resolveComponent } = Perysee.Vue;
    const { router } = Perysee.Inertia;

    const Section = {
        name: "WhiteLabelSection",
        inheritAttrs: false,
        props: ["modules", "errors"],
        setup(props) {
            // Members receive an empty array, which is truthy in JavaScript: only the form data has links.
            const data = computed(() => {
                const value = props.modules?.data?.["white-label"];
                return value?.links ? value : null;
            });
            const form = reactive({ name: "", accent: "" });
            const busy = ref("");
            const confirming = ref(false);
            watch(
                data,
                (value) => {
                    form.name = value?.name ?? "";
                    form.accent = value?.accent ?? "";
                },
                { immediate: true },
            );

            const send = (key, method, url, payload, options = {}) => {
                busy.value = key;
                const visit = { preserveScroll: true, ...options, onFinish: () => (busy.value = "") };
                // Inertia's delete takes (url, options); post takes (url, data, options).
                method === "delete" ? router.delete(url, visit) : router[method](url, payload, visit);
            };
            const save = (event) => {
                event?.preventDefault();
                send("save", "post", data.value.links.save, { name: form.name, accent: form.accent });
            };
            const reset = () => {
                confirming.value = false;
                send("reset", "delete", data.value.links.reset, {});
            };
            const upload = (event) => {
                const file = event.target.files?.[0];
                if (file) {
                    send("logo", "post", data.value.links.logo, { logo: file }, { forceFormData: true });
                }
                event.target.value = "";
            };

            return () => {
                const value = data.value;
                if (!value) {
                    return null;
                }
                const Card = resolveComponent("UCard");
                const Field = resolveComponent("UFormField");
                const Input = resolveComponent("UInput");
                const Button = resolveComponent("UButton");
                const Popover = resolveComponent("UPopover");
                const ColorPicker = resolveComponent("UColorPicker");
                const accent = form.accent || value.defaults.accent;
                const useDefault = (label, show, clear) => h(Button, { label, color: "neutral", variant: "link", size: "xs", disabled: !show, onClick: clear });

                return h(Card, { class: "mt-6" }, {
                    default: () => [
                        h("div", { class: "mb-5" }, [
                            h("h2", { class: "text-base font-medium" }, "White label"),
                            h("p", { class: "text-sm text-muted mt-1" }, "Replace the Perysee name, logo and accent color across the interface and the sign-in page."),
                        ]),
                        h("form", { class: "space-y-5", onSubmit: save }, [
                            h(Field, { label: "Product name", hint: `Leave empty to keep ${value.defaults.name}`, error: props.errors?.name }, {
                                default: () => h("div", { class: "flex items-center gap-2" }, [
                                    h(Input, {
                                        modelValue: form.name,
                                        "onUpdate:modelValue": (next) => (form.name = next),
                                        placeholder: value.defaults.name,
                                        maxlength: 40,
                                        class: "w-full max-w-sm",
                                    }),
                                    useDefault("Use default", !!form.name, () => (form.name = "")),
                                ]),
                            }),
                            h(Field, { label: "Accent color", hint: "Used for buttons, links and charts", error: props.errors?.accent }, {
                                default: () => h("div", { class: "flex flex-wrap items-center gap-2" }, [
                                    h(Popover, null, {
                                        default: () => h(Button, { color: "neutral", variant: "outline", "aria-label": "Pick the accent color" }, {
                                            leading: () => h("span", { class: "size-4 border border-default", style: { background: accent } }),
                                            default: () => accent,
                                        }),
                                        content: () => h("div", { class: "p-3" }, [
                                            h(ColorPicker, {
                                                modelValue: accent,
                                                "onUpdate:modelValue": (next) => (form.accent = next),
                                                format: "hex",
                                            }),
                                        ]),
                                    }),
                                    h(Input, {
                                        modelValue: form.accent,
                                        "onUpdate:modelValue": (next) => (form.accent = next),
                                        placeholder: value.defaults.accent,
                                        maxlength: 7,
                                        class: "w-28",
                                    }),
                                    useDefault("Use default", !!form.accent, () => (form.accent = "")),
                                ]),
                            }),
                            h("div", { class: "flex flex-wrap items-center gap-3" }, [
                                h(Button, { type: "submit", label: "Save", loading: busy.value === "save" }),
                                confirming.value
                                    ? [
                                        h("span", { class: "text-sm text-muted" }, "This removes the name, color and logo."),
                                        h(Button, { label: "Yes, reset", color: "error", loading: busy.value === "reset", onClick: reset }),
                                        h(Button, { label: "Cancel", color: "neutral", variant: "ghost", onClick: () => (confirming.value = false) }),
                                    ]
                                    : h(Button, { label: "Reset everything to Perysee", color: "neutral", variant: "outline", icon: "i-tabler-restore", onClick: () => (confirming.value = true) }),
                            ]),
                        ]),
                        h("div", { class: "mt-8 border-t border-default pt-6" }, [
                            h(Field, { label: "Logo", hint: "PNG, JPEG, WebP or SVG, 256 KB at most", error: props.errors?.logo }, {
                                default: () => h("div", { class: "flex flex-wrap items-center gap-4" }, [
                                    value.logo_url
                                        ? h("img", { src: value.logo_url, alt: "Current logo", class: "h-10 max-w-40 object-contain border border-default p-1" })
                                        : h("span", { class: "text-sm text-muted" }, "No logo, the Perysee logo is shown"),
                                    h("input", {
                                        type: "file",
                                        accept: "image/png,image/jpeg,image/webp,image/svg+xml",
                                        "aria-label": "Upload a logo",
                                        class: "text-sm",
                                        disabled: busy.value === "logo",
                                        onChange: upload,
                                    }),
                                    value.has_logo
                                        ? h(Button, {
                                            label: "Remove logo",
                                            color: "neutral",
                                            variant: "ghost",
                                            onClick: () => send("remove", "delete", value.links.logo, {}),
                                        })
                                        : null,
                                ]),
                            }),
                        ]),
                    ],
                });
            };
        },
    };

    Perysee.registerSettings("white-label", Section);
})();
