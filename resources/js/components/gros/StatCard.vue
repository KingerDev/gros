<script setup lang="ts">
// Malá štatistická karta: ikona + label, veľká hodnota, voliteľná pätička (slot).
defineProps<{ label: string; value: string; valueColor?: string; iconBg?: string }>();
</script>

<template>
    <div style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
        <div style="display: flex; align-items: center; gap: 8px">
            <span
                v-if="$slots.icon"
                style="width: 28px; height: 28px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0"
                :style="{ background: iconBg ?? '#f1efe8' }"
            >
                <slot name="icon" />
            </span>
            <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">{{ label }}</div>
        </div>
        <div
            class="font-display"
            style="font-weight: 800; font-size: 28px; letter-spacing: -0.8px; margin-top: 10px"
            :style="{ color: valueColor ?? '#20212e' }"
        >
            {{ value }}
        </div>
        <slot />
    </div>
</template>
