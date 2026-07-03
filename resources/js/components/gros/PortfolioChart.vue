<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';

interface Point { ym: string; label: string; value: number; invested: number }
const props = defineProps<{ series: Point[] }>();

const { eur, primary } = useGros();

const wrap = ref<HTMLElement | null>(null);
const { width } = useElementSize(wrap);

const H = 240;
const padT = 16;
const padB = 26;
const padX = 10;
const W = computed(() => Math.max(300, width.value));

const maxV = computed(() => Math.max(1, ...props.series.flatMap((p) => [p.value, p.invested])));

function x(i: number): number {
    const n = props.series.length;
    return n <= 1 ? padX : padX + (i / (n - 1)) * (W.value - 2 * padX);
}
function y(v: number): number {
    return H - padB - (v / maxV.value) * (H - padT - padB);
}

const valueLine = computed(() => props.series.map((p, i) => `${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' '));
const investedLine = computed(() => props.series.map((p, i) => `${x(i).toFixed(1)},${y(p.invested).toFixed(1)}`).join(' '));
const valueArea = computed(() => {
    if (!props.series.length) return '';
    const top = props.series.map((p, i) => `${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' L');
    return `M${x(0).toFixed(1)},${(H - padB).toFixed(1)} L${top} L${x(props.series.length - 1).toFixed(1)},${(H - padB).toFixed(1)} Z`;
});

// popisky rokov (január)
const yearTicks = computed(() =>
    props.series.map((p, i) => ({ i, label: p.ym.slice(0, 4), isJan: p.ym.slice(5) === '01' })).filter((t) => t.isJan),
);

// hover
const hoverIdx = ref<number | null>(null);
function onMove(e: MouseEvent) {
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const px = e.clientX - rect.left;
    const n = props.series.length;
    if (n < 2) return;
    const i = Math.round(((px - padX) / (W.value - 2 * padX)) * (n - 1));
    hoverIdx.value = Math.max(0, Math.min(n - 1, i));
}
const hover = computed(() => (hoverIdx.value !== null ? props.series[hoverIdx.value] : null));
</script>

<template>
    <div ref="wrap" style="position: relative; width: 100%" @mousemove="onMove" @mouseleave="hoverIdx = null">
        <svg :width="W" :height="H" style="display: block">
            <defs>
                <linearGradient id="pfArea" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" :stop-color="primary" stop-opacity="0.22" />
                    <stop offset="100%" :stop-color="primary" stop-opacity="0" />
                </linearGradient>
            </defs>
            <!-- year gridlines -->
            <line v-for="t in yearTicks" :key="'g' + t.i" :x1="x(t.i)" :x2="x(t.i)" :y1="padT" :y2="H - padB" stroke="#f1efe8" stroke-width="1" />
            <text v-for="t in yearTicks" :key="'t' + t.i" :x="x(t.i)" :y="H - 8" fill="#9a9cab" font-size="11" font-weight="700" text-anchor="middle">{{ t.label }}</text>

            <!-- value area + lines -->
            <path :d="valueArea" fill="url(#pfArea)" />
            <polyline :points="investedLine" fill="none" stroke="#b8b6ac" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round" />
            <polyline :points="valueLine" fill="none" :stroke="primary" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />

            <!-- hover -->
            <template v-if="hover && hoverIdx !== null">
                <line :x1="x(hoverIdx)" :x2="x(hoverIdx)" :y1="padT" :y2="H - padB" stroke="#20212e" stroke-width="1" stroke-opacity="0.25" />
                <circle :cx="x(hoverIdx)" :cy="y(hover.invested)" r="3.5" fill="#b8b6ac" />
                <circle :cx="x(hoverIdx)" :cy="y(hover.value)" r="4.5" :fill="primary" stroke="#fff" stroke-width="2" />
            </template>
        </svg>

        <!-- tooltip -->
        <div
            v-if="hover"
            style="position: absolute; top: 0; background: #20212e; color: #fff; padding: 8px 11px; border-radius: 10px; font-size: 12px; font-weight: 600; pointer-events: none; white-space: nowrap; box-shadow: 0 8px 20px rgba(20, 18, 30, 0.25)"
            :style="{ left: Math.min(Math.max(0, x(hoverIdx as number) - 60), W - 130) + 'px' }"
        >
            <div style="opacity: 0.7; margin-bottom: 3px">{{ hover.label }}</div>
            <div>Hodnota: <b>{{ eur(hover.value) }}</b></div>
            <div style="opacity: 0.85">Vklad: {{ eur(hover.invested) }}</div>
            <div :style="{ color: hover.value - hover.invested >= 0 ? '#63e6be' : '#ff8a80' }">{{ hover.value - hover.invested >= 0 ? '+' : '−' }}{{ eur(hover.value - hover.invested) }}</div>
        </div>

        <!-- legend -->
        <div style="display: flex; align-items: center; gap: 16px; margin-top: 6px; padding-left: 4px">
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 14px; height: 3px; border-radius: 2px" :style="{ background: primary }"></span>Hodnota</span>
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a"><span style="width: 14px; height: 0; border-top: 3px dashed #b8b6ac"></span>Vklad</span>
        </div>
    </div>
</template>
