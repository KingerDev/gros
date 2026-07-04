<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { computed } from 'vue';

// Medziobdobná zmena ▲/▼ X %. `invert` = rast je zlý (výdavky).
const props = defineProps<{ pct: number | null; invert?: boolean; label?: string }>();

const { num } = useGros();

const good = computed(() => (props.pct === null ? true : props.invert ? props.pct <= 0 : props.pct >= 0));
</script>

<template>
    <div v-if="pct !== null" style="font-size: 12px; font-weight: 700; margin-top: 7px; display: flex; align-items: center; gap: 5px">
        <span :style="{ color: good ? '#2ba35a' : '#e8544e' }">{{ pct >= 0 ? '▲' : '▼' }} {{ num(Math.abs(pct), 1) }} %</span>
        <span v-if="label" style="color: #9a9cab; font-weight: 600">{{ label }}</span>
    </div>
</template>
