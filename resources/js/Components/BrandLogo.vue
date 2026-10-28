<script setup>
import { Link, usePage } from "@inertiajs/vue3";
import brand from "../brand-version.json";
const page = usePage();
</script>
<template>
    <Link
        :href="page.props.links['sites.index']"
        class="perysee-brand"
        aria-label="Perysee"
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
        /><span>perysee<span class="text-primary">.</span></span></Link
    >
</template>
