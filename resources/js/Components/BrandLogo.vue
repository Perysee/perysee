<script setup>
import { Link, usePage } from "@inertiajs/vue3";
const page = usePage();
</script>
<template>
    <Link
        :href="page.props.links['sites.index']"
        class="perysee-brand"
        aria-label="Perysee"
        ><img
            class="brand-logo-light"
            :src="page.props.assets.logo"
            width="95"
            height="96"
            alt=""
        /><img
            class="brand-logo-dark"
            :src="page.props.assets.logo.replace(/logo\.svg$/, 'logo-dark.svg')"
            width="95"
            height="96"
            alt=""
        /><span>perysee<span class="text-primary">.</span></span></Link
    >
</template>
