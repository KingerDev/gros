<script setup lang="ts">
import { computed } from 'vue';

// SVG čiarový graf s plochou (vývoj čistého imania a pod.).
interface Point {
    label: string;
    value: number;
    title?: string;
}

const props = withDefaults(defineProps<{ points: Point[]; color: string; height?: number; fmt?: (n: number) => string }>(), {
    height: 210,
    fmt: (n: number) => String(Math.round(n)),
});

const W = 600;
const PAD = 8;

const uid = `lc-${Math.random().toString(36).slice(2, 9)}`;

const domain = computed(() => {
    const vals = props.points.map((p) => p.value);
    let lo = Math.min(...vals);
    let hi = Math.max(...vals);
    const range = hi - lo || Math.max(1, Math.abs(hi));
    lo -= range * 0.08;
    hi += range * 0.08;
    return { lo, hi };
});

function x(i: number): number {
    return props.points.length > 1 ? (i / (props.points.length - 1)) * W : W / 2;
}

function y(v: number): number {
    const { lo, hi } = domain.value;
    return PAD + (1 - (v - lo) / (hi - lo)) * (props.height - 2 * PAD);
}

const linePath = computed(() => props.points.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' '));
const areaPath = computed(() => (props.points.length > 1 ? `${linePath.value} L${W},${props.height} L0,${props.height} Z` : ''));

// Pri veľa bodoch zobraz len časť x-labelov
const labelStep = computed(() => Math.max(1, Math.ceil(props.points.length / 12)));
</script>

<template>
    <div>
        <div style="position: relative">
            <svg :viewBox="`0 0 ${W} ${height}`" :style="{ width: '100%', height: height + 'px', display: 'block' }" preserveAspectRatio="none">
                <defs>
                    <linearGradient :id="uid" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="color" stop-opacity="0.22" />
                        <stop offset="100%" :stop-color="color" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <path v-if="areaPath" :d="areaPath" :fill="`url(#${uid})`" />
                <path
                    :d="linePath"
                    fill="none"
                    :stroke="color"
                    stroke-width="2.5"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    vector-effect="non-scaling-stroke"
                />
                <rect
                    v-for="(p, i) in points"
                    :key="i"
                    :x="x(i) - W / points.length / 2"
                    y="0"
                    :width="W / points.length"
                    :height="height"
                    fill="transparent"
                >
                    <title>{{ p.title ?? `${p.label}: ${fmt(p.value)}` }}</title>
                </rect>
            </svg>
            <span style="position: absolute; top: 2px; left: 4px; font-size: 10.5px; font-weight: 700; color: #b0b2bd">{{ fmt(domain.hi) }}</span>
            <span style="position: absolute; bottom: 2px; left: 4px; font-size: 10.5px; font-weight: 700; color: #b0b2bd">{{ fmt(domain.lo) }}</span>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 6px">
            <span
                v-for="(p, i) in points"
                :key="i"
                style="flex: 1; text-align: center; font-size: 10.5px; font-weight: 700; color: #9a9cab; white-space: nowrap; overflow: hidden"
            >
                {{ i % labelStep === 0 ? p.label : '' }}
            </span>
        </div>
    </div>
</template>
