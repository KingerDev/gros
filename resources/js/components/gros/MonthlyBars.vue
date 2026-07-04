<script setup lang="ts">
import { computed } from 'vue';

// Skupinový stĺpcový graf (napr. príjmy/výdavky po mesiacoch).
// Každá položka má label a N stĺpcov {value, color|gradient, title}.
interface Bar {
    value: number;
    color: string;
    title?: string;
}

const props = withDefaults(
    defineProps<{
        items: { label: string; bars: Bar[] }[];
        height?: number;
        barMax?: number;
        rotateLabels?: boolean;
    }>(),
    { height: 180, barMax: 20, rotateLabels: false },
);

const max = computed(() => Math.max(1, ...props.items.flatMap((i) => i.bars.map((b) => b.value))));
</script>

<template>
    <div style="display: flex; align-items: flex-end; gap: 8px" :style="{ height: height + 'px' }">
        <div
            v-for="(item, i) in items"
            :key="i"
            style="
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                height: 100%;
                justify-content: flex-end;
                min-width: 0;
            "
        >
            <div style="display: flex; align-items: flex-end; gap: 3px; width: 100%; justify-content: center; flex: 1">
                <div
                    v-for="(bar, j) in item.bars"
                    :key="j"
                    :title="bar.title"
                    :style="{
                        width: Math.floor(88 / item.bars.length) + '%',
                        maxWidth: barMax + 'px',
                        height: (bar.value / max) * 100 + '%',
                        background: bar.color,
                        borderRadius: '4px 4px 0 0',
                        transition: 'height .5s ease',
                    }"
                ></div>
            </div>
            <div
                style="font-size: 11px; font-weight: 700; color: #9a9cab; white-space: nowrap"
                :style="rotateLabels ? { transform: 'rotate(-45deg)', transformOrigin: 'center', height: '14px', fontSize: '9.5px' } : {}"
            >
                {{ item.label }}
            </div>
        </div>
    </div>
</template>
