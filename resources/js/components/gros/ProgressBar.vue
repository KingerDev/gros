<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ pct: number; color: string; height?: number }>(), { height: 9 });

const clamped = computed(() => Math.max(0, Math.min(100, props.pct)));
</script>

<template>
    <div style="background: #f1efe8; border-radius: 6px; overflow: hidden" :style="{ height: height + 'px' }">
        <div :style="{ height: '100%', width: clamped + '%', background: color, borderRadius: '6px', transition: 'width .3s' }"></div>
    </div>
</template>
