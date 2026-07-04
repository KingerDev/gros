<script setup lang="ts">
import { computed } from 'vue';

// Donut z conic-gradientu; stred je biely kruh so slotom (súčet a pod.).
const props = withDefaults(defineProps<{ parts: { color: string; value: number }[]; size?: number; inset?: number }>(), {
    size: 150,
    inset: 26,
});

const total = computed(() => props.parts.reduce((s, p) => s + p.value, 0) || 1);

const gradientStyle = computed(() => {
    let acc = 0;
    const stops = props.parts.map((p) => {
        const from = acc;
        acc += (p.value / total.value) * 100;
        return `${p.color} ${from.toFixed(2)}% ${acc.toFixed(2)}%`;
    });
    return stops.length ? `conic-gradient(${stops.join(', ')})` : '#f1efe8';
});
</script>

<template>
    <div
        style="position: relative; flex-shrink: 0; border-radius: 50%"
        :style="{ width: size + 'px', height: size + 'px', background: gradientStyle }"
    >
        <div
            style="
                position: absolute;
                background: #fff;
                border-radius: 50%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            "
            :style="{ inset: inset + 'px' }"
        >
            <slot />
        </div>
    </div>
</template>
