<script setup lang="ts">
// Biela karta s voliteľným titulkom a slotom "right" v hlavičke.
defineProps<{ title?: string }>();
</script>

<template>
    <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
        <div v-if="title || $slots.right" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px">
            <div v-if="title" class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">{{ title }}</div>
            <slot name="right" />
        </div>
        <slot />
    </div>
</template>
