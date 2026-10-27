<script setup>
import {useForm} from '@inertiajs/vue3';
const props=defineProps(['initial','steps','url','editing']);
const emit=defineEmits(['saved']);
const form=useForm({name:props.initial?.name || '',steps:props.steps?.map(step=>({...step})) || [{type:'path',value:'/',label:''},{type:'event',value:'signup',label:''}]});
const submit=()=>form[props.editing?'put':'post'](props.url,{preserveScroll:true,onSuccess:()=>emit('saved')});
</script>
<template>
<UForm :state="form" class="space-y-5" @submit="submit"><UFormField label="Funnel name" :error="form.errors.name"><UInput v-model="form.name" maxlength="120" class="w-full" required/></UFormField>
 <p class="text-xs text-muted">2–8 ordered steps, within the same session. Intermediate actions are allowed.</p>
 <div v-for="(step,index) in form.steps" :key="index" class="border border-default p-4 space-y-3"><div class="flex justify-between items-center"><p class="text-xs text-muted">Step {{index+1}}</p><div class="flex gap-1"><UButton icon="i-tabler-arrow-up" :disabled="index===0" :aria-label="'Move step '+(index+1)+' up'" variant="ghost" color="neutral" @click="[form.steps[index-1],form.steps[index]]=[form.steps[index],form.steps[index-1]]"/><UButton icon="i-tabler-trash" :disabled="form.steps.length<=2" :aria-label="'Remove step '+(index+1)" variant="ghost" color="neutral" @click="form.steps.splice(index,1)"/></div></div><div class="grid sm:grid-cols-2 gap-3"><USelect v-model="step.type" :items="[{value:'path',label:'Page path'},{value:'event',label:'Named event'}]" :aria-label="'Step '+(index+1)+' type'"/><UInput v-model="step.value" :placeholder="step.type==='path'?'/pricing':'signup'" :aria-label="'Step '+(index+1)+' exact value'" required/></div><UInput v-model="step.label" placeholder="Display label (optional)" :aria-label="'Step '+(index+1)+' label'" class="w-full"/><p v-for="(message,key) in Object.fromEntries(Object.entries(form.errors).filter(([key])=>key.startsWith('steps.'+index)))" :key="key" class="text-xs text-error" role="alert">{{message}}</p></div>
 <p v-if="form.errors.steps" role="alert" class="text-sm text-error">{{form.errors.steps}}</p><div class="flex gap-3"><UButton label="Add step" icon="i-tabler-plus" color="neutral" variant="outline" :disabled="form.steps.length>=8" @click="form.steps.push({type:'path',value:'',label:''})"/><UButton type="submit" :label="editing?'Save new version':'Create funnel'" :loading="form.processing"/></div>
</UForm>
</template>
