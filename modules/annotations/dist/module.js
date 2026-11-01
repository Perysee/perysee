// Annotations: a page per site, a block under the site reports, and a settings page.
(function () {
    const { h, reactive, ref, computed, resolveComponent } = Perysee.Vue;
    const { router, Link } = Perysee.Inertia;

    const today = () => new Date().toISOString().slice(0, 10);

    // The page of notes of one site, reached from the site menu.
    const NotesPage = {
        name: "AnnotationsPage",
        inheritAttrs: false,
        props: ["website", "notes", "canWrite", "links", "errors"],
        setup(props) {
            const form = reactive({ noted_on: today(), body: "" });
            const busy = ref(false);
            const add = (event) => {
                event?.preventDefault();
                busy.value = true;
                router.post(props.links.store, { ...form }, {
                    preserveScroll: true,
                    onSuccess: () => (form.body = ""),
                    onFinish: () => (busy.value = false),
                });
            };
            const remove = (note) => router.delete(`${props.links.store}/${note.id}`, { preserveScroll: true });

            return () => {
                const Card = resolveComponent("UCard");
                const Field = resolveComponent("UFormField");
                const Input = resolveComponent("UInput");
                const Button = resolveComponent("UButton");

                return h("div", [
                    h("div", { class: "page-heading" }, [
                        h("div", [
                            h("p", { class: "eyebrow" }, props.website.name),
                            h("h1", "Annotations"),
                            h("p", { class: "text-sm text-muted" }, "Dated notes about this site: a launch, a campaign, an incident."),
                        ]),
                    ]),
                    props.canWrite
                        ? h(Card, { class: "mb-6" }, {
                            default: () => h("form", { class: "flex flex-wrap items-end gap-4", onSubmit: add }, [
                                h(Field, { label: "Date", error: props.errors?.noted_on }, {
                                    default: () => h("input", {
                                        type: "date",
                                        value: form.noted_on,
                                        class: "h-9 border border-default bg-transparent px-2 text-sm",
                                        onInput: (event) => (form.noted_on = event.target.value),
                                    }),
                                }),
                                h(Field, { label: "Note", error: props.errors?.body, class: "flex-1 min-w-64" }, {
                                    default: () => h(Input, {
                                        modelValue: form.body,
                                        "onUpdate:modelValue": (next) => (form.body = next),
                                        maxlength: 280,
                                        placeholder: "What happened?",
                                        class: "w-full",
                                    }),
                                }),
                                h(Button, { type: "submit", label: "Add note", loading: busy.value }),
                            ]),
                        })
                        : null,
                    props.notes.length
                        ? h(Card, null, {
                            default: () => h("ul", { class: "divide-y divide-default" }, props.notes.map((note) =>
                                h("li", { key: note.id, class: "py-3 first:pt-0 last:pb-0 flex items-start justify-between gap-4" }, [
                                    h("div", { class: "min-w-0" }, [
                                        h("p", { class: "text-sm" }, note.body),
                                        h("p", { class: "text-xs text-muted mt-1" }, `${note.noted_on}${note.author ? ` · ${note.author}` : ""}`),
                                    ]),
                                    props.canWrite
                                        ? h(Button, { icon: "i-tabler-trash", color: "neutral", variant: "ghost", "aria-label": "Delete note", onClick: () => remove(note) })
                                        : null,
                                ]),
                            )),
                        })
                        : h("p", { class: "text-sm text-muted" }, "No annotation yet."),
                ]);
            };
        },
    };

    // A short block under every site report: the latest notes, and a way to the whole list.
    const LatestNotes = {
        name: "AnnotationsLatest",
        inheritAttrs: false,
        props: ["modules"],
        setup(props) {
            const data = computed(() => props.modules?.data?.annotations);
            return () => {
                const recent = data.value?.recent;
                if (!recent?.length) {
                    return null;
                }

                return h(resolveComponent("UCard"), { class: "mt-6" }, {
                    default: () => [
                        h("div", { class: "flex items-center justify-between gap-4 mb-3" }, [
                            h("h2", { class: "text-base font-medium" }, "Latest annotations"),
                            h(Link, { href: data.value.page, class: "text-sm text-primary" }, () => "All annotations"),
                        ]),
                        h("ul", { class: "space-y-2" }, recent.map((note) =>
                            h("li", { key: note.id, class: "text-sm" }, [h("span", { class: "text-muted mr-2" }, note.noted_on), note.body]),
                        )),
                    ],
                });
            };
        },
    };

    // The retention setting, under Settings > Modules > Annotations.
    const Settings = {
        name: "AnnotationsSettings",
        inheritAttrs: false,
        props: ["modules", "errors"],
        setup(props) {
            const data = computed(() => {
                const value = props.modules?.data?.annotations?.settings;
                return value?.links ? value : null;
            });
            const days = ref(String(data.value?.retention_days ?? 730));
            const busy = ref(false);
            const save = (event) => {
                event?.preventDefault();
                busy.value = true;
                router.post(data.value.links.save, { retention_days: days.value }, { preserveScroll: true, onFinish: () => (busy.value = false) });
            };

            return () => {
                if (!data.value) {
                    return null;
                }
                const Field = resolveComponent("UFormField");

                return h(resolveComponent("UCard"), { class: "mt-6" }, {
                    default: () => [
                        h("h2", { class: "text-base font-medium" }, "Retention"),
                        h("p", { class: "text-sm text-muted mt-1 mb-5" }, "Notes older than this are deleted every day."),
                        h("form", { class: "space-y-5", onSubmit: save }, [
                            h(Field, { label: "Keep notes for (days)", hint: "Between 30 and 3650", error: props.errors?.retention_days }, {
                                default: () => h(resolveComponent("UInput"), {
                                    modelValue: days.value,
                                    "onUpdate:modelValue": (next) => (days.value = next),
                                    inputmode: "numeric",
                                    class: "w-32",
                                }),
                            }),
                            h(resolveComponent("UButton"), { type: "submit", label: "Save", loading: busy.value }),
                        ]),
                    ],
                });
            };
        },
    };

    Perysee.registerScreen("module:annotations", NotesPage);
    Perysee.registerSlot("site.report.after", LatestNotes, { module: "annotations" });
    Perysee.registerSettings("annotations", Settings);
})();
