<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import brand from "../brand-version.json";
const page = usePage();
</script>
<template>
    <Link
        :href="page.props.links['sites.index']"
        class="perysee-brand"
        :aria-label="page.props.branding.name"
        ><img
            v-if="page.props.branding.logo"
            class="brand-logo-custom"
            :src="page.props.branding.logo"
            alt=""
        /><template v-else
            ><img
            class="brand-logo-light"
            :src="`${page.props.assets.logo}?v=${brand.version}`"
            width="304"
            height="416"
            alt=""
        /><img
            class="brand-logo-dark"
            :src="`${page.props.assets.logo.replace(/logo\.svg$/, 'logo-dark.svg')}?v=${brand.version}`"
            width="304"
            height="416"
            alt=""
        /></template
        ><span v-if="page.props.branding.name !== 'Perysee'">{{
            page.props.branding.name
        }}</span
        ><span v-else>perysee<span class="text-primary">.</span></span></Link
    >
</template>
