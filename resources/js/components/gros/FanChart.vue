<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';

export interface FanPoint {
    year: number;
    p10: number;
    p25: number;
    p50: number;
    p75: number;
    p90: number;
    contributed: number;
}

const props = defineProps<{ series: FanPoint[] }>();

const { eur, num, primary } = useGros();

const wrap = ref<HTMLElement | null>(null);
const { width } = useElementSize(wrap);

const H = 280;
const padT = 14;
const padB = 26;
const padL = 54;
const padR = 10;
const W = computed(() => Math.max(320, width.value));

const maxV = computed(() => Math.max(1, ...props.series.map((p) => p.p90)));

function x(i: number): number {
    const n = props.series.length;
    return n <= 1 ? padL : padL + (i / (n - 1)) * (W.value - padL - padR);
}
function y(v: number): number {
    return H - padB - (v / maxV.value) * (H - padT - padB);
}

/** Pás medzi dvoma percentilmi ako uzavretá plocha. */
function band(lo: keyof FanPoint, hi: keyof FanPoint): string {
    if (!props.series.length) return '';
    const up = props.series.map((p, i) => `${x(i).toFixed(1)},${y(p[hi] as number).toFixed(1)}`);
    const down = props.series.map((p, i) => `${x(i).toFixed(1)},${y(p[lo] as number).toFixed(1)}`).reverse();
    return `M${up.join(' L')} L${down.join(' L')} Z`;
}
function line(key: keyof FanPoint): string {
    return props.series.map((p, i) => `${x(i).toFixed(1)},${y(p[key] as number).toFixed(1)}`).join(' ');
}

const outer = computed(() => band('p10', 'p90'));
const inner = computed(() => band('p25', 'p75'));
const median = computed(() => line('p50'));
const contributed = computed(() => line('contributed'));

/** Krátky zápis veľkých súm na os (1,2 mil.). */
function short(v: number): string {
    if (v >= 1_000_000) return num(v / 1_000_000, 1) + ' mil.';
    if (v >= 1_000) return Math.round(v / 1_000) + ' tis.';
    return num(v);
}

const yTicks = computed(() => {
    const step = maxV.value / 4;
    return [0, 1, 2, 3, 4].map((k) => ({ v: k * step, label: short(k * step) }));
});

// popisky rokov — každých 5 rokov, vždy prvý a posledný
const xTicks = computed(() => {
    const n = props.series.length;
    return props.series
        .map((p, i) => ({ i, year: p.year }))
        .filter((t) => t.i === 0 || t.i === n - 1 || t.year % 5 === 0);
});

const hoverIdx = ref<number | null>(null);
function onMove(e: MouseEvent) {
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const n = props.series.length;
    if (n < 2) return;
    const i = Math.round(((e.clientX - rect.left - padL) / (W.value - padL - padR)) * (n - 1));
    hoverIdx.value = Math.max(0, Math.min(n - 1, i));
}
const hover = computed(() => (hoverIdx.value !== null ? props.series[hoverIdx.value] : null));
const tooltipLeft = computed(() => {
    if (hoverIdx.value === null) return 0;
    return Math.min(Math.max(0, x(hoverIdx.value) - 80), W.value - 190);
});
</script>

<template>
    <div ref="wrap" style="position: relative; width: 100%" @mousemove="onMove" @mouseleave="hoverIdx = null">
        <svg :width="W" :height="H" style="display: block">
            <!-- os Y -->
            <g v-for="t in yTicks" :key="'y' + t.v">
                <line :x1="padL" :x2="W - padR" :y1="y(t.v)" :y2="y(t.v)" stroke="#f1efe8" stroke-width="1" />
                <text :x="padL - 8" :y="y(t.v) + 4" fill="#b0b2bd" font-size="10.5" font-weight="700" text-anchor="end">{{ t.label }}</text>
            </g>

            <!-- pásma pravdepodobnosti: jeden odtieň, tmavší = pravdepodobnejší -->
            <path :d="outer" :fill="primary" fill-opacity="0.12" />
            <path :d="inner" :fill="primary" fill-opacity="0.22" />

            <!-- vklady (nominálne) a medián -->
            <polyline :points="contributed" fill="none" stroke="#b8b6ac" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round" />
            <polyline :points="median" fill="none" :stroke="primary" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" />

            <!-- popisky rokov -->
            <text v-for="t in xTicks" :key="'x' + t.i" :x="x(t.i)" :y="H - 8" fill="#9a9cab" font-size="10.5" font-weight="700" text-anchor="middle">
                {{ t.year }}
            </text>

            <template v-if="hover && hoverIdx !== null">
                <line :x1="x(hoverIdx)" :x2="x(hoverIdx)" :y1="padT" :y2="H - padB" stroke="#20212e" stroke-width="1" stroke-opacity="0.25" />
                <circle :cx="x(hoverIdx)" :cy="y(hover.contributed)" r="3.5" fill="#b8b6ac" stroke="#fff" stroke-width="2" />
                <circle :cx="x(hoverIdx)" :cy="y(hover.p50)" r="4.5" :fill="primary" stroke="#fff" stroke-width="2" />
            </template>
        </svg>

        <div
            v-if="hover"
            style="position: absolute; top: 0; background: #20212e; color: #fff; padding: 9px 12px; border-radius: 11px; font-size: 12px; font-weight: 600; pointer-events: none; white-space: nowrap; box-shadow: 0 8px 20px rgba(20, 18, 30, 0.25)"
            :style="{ left: tooltipLeft + 'px' }"
        >
            <div style="opacity: 0.7; margin-bottom: 4px">Rok {{ hover.year }}</div>
            <div>Optimisticky: <b>{{ eur(hover.p90) }}</b></div>
            <div>Stred: <b>{{ eur(hover.p50) }}</b></div>
            <div>Pesimisticky: <b>{{ eur(hover.p10) }}</b></div>
            <div style="opacity: 0.7; margin-top: 3px">Vložené: {{ eur(hover.contributed) }}</div>
        </div>

        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 16px; margin-top: 8px; padding-left: 4px">
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a">
                <span style="width: 14px; height: 3px; border-radius: 2px" :style="{ background: primary }"></span>Stredný scenár
            </span>
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a">
                <span style="width: 14px; height: 11px; border-radius: 3px" :style="{ background: primary, opacity: 0.22 }"></span>50 % scenárov
            </span>
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a">
                <span style="width: 14px; height: 11px; border-radius: 3px" :style="{ background: primary, opacity: 0.12 }"></span>80 % scenárov
            </span>
            <span style="display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; color: #6a6c7a">
                <span style="width: 14px; height: 0; border-top: 3px dashed #b8b6ac"></span>Vložené peniaze
            </span>
        </div>
    </div>
</template>
