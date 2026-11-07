<script setup>
import {useForm} from '@inertiajs/vue3';
const props=defineProps(['initial','url','editing']);
const emit=defineEmits(['saved']);
const form=useForm({name:props.initial?.name || '',type:props.initial?.type || 'event',value:props.initial?.value || '',threshold:props.initial?.threshold || 1});
const submit=()=>form[props.editing?'put':'post'](props.url,{preserveScroll:true,onSuccess:()=>emit('saved')});
</script>
<template>
<UForm :state="form" class="space-y-5" @submit="submit">
 <UFormField label="Goal name" :error="form.errors.name"><UInput v-model="form.name" maxlength="120" class="w-full" required/></UFormField>
 <div class="grid sm:grid-cols-2 gap-3">
  <UFormField label="Goal type" :error="form.errors.type"><USelect v-model="form.type" :items="[{value:'event',label:'Named event'},{value:'path',label:'Page path'}]" class="w-full"/></UFormField>
  <UFormField :label="form.type==='event'?'Event name':'Exact page path'" :error="form.errors.value"><UInput v-model="form.value" :placeholder="form.type==='event'?'signup':'/thanks'" maxlength="512" class="w-full" required/></UFormField>
 </div>
 <UFormField label="Reached on the Nth matching hit of a session" description="1 converts on the first hit. A session converts once per goal." :error="form.errors.threshold"><UInput v-model.number="form.threshold" type="number" min="1" max="1000" class="w-32" required/></UFormField>
 <p v-if="editing" class="text-xs text-muted">Saving creates a new version. Past events still retained are counted again for it; earlier versions keep their figures.</p>
 <UButton type="submit" :label="editing?'Save new version':'Create goal'" :loading="form.processing"/>
</UForm>
</template>
