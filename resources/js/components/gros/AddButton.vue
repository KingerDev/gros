<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { useMediaQuery } from '@vueuse/core';

const props = defineProps<{ label: string }>();
const emit = defineEmits<{ click: [] }>();

const { primary, primarySoft } = useGros();
const isDesktop = useMediaQuery('(min-width: 860px)');
</script>

<template>
    <button
        type="button"
        style="display: flex; align-items: center; gap: 7px; color: #fff; font-weight: 700; font-size: 14px; padding: 11px 17px; border-radius: 13px; white-space: nowrap"
        :style="{ background: primary, boxShadow: `0 8px 18px ${primarySoft}` }"
        @click="emit('click')"
    >
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
        {{ isDesktop ? props.label : 'Pridať' }}
    </button>
</template>
