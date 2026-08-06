<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    source?: string | null;
    excluded?: boolean;
    reason?: string | null;
}>();

const autoLabel = computed(() => (props.source === 'loan' ? 'Automatická splátka úveru' : 'Automatická platba predplatného'));
</script>

<template>
    <span
        v-if="source"
        style="
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 10.5px;
            font-weight: 800;
            color: #2a6ebd;
            background: #eef6ff;
            padding: 3px 7px;
            border-radius: 7px;
            white-space: nowrap;
        "
        :title="autoLabel"
    >
        <svg
            width="11"
            height="11"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
        >
            <path d="M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5" />
            <path d="M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5" />
        </svg>
        AUTO
    </span>
    <span
        v-if="excluded"
        style="font-size: 10.5px; font-weight: 800; color: #a06a1e; background: #fff2dd; padding: 3px 7px; border-radius: 7px; white-space: nowrap"
        :title="reason ?? ''"
    >
        MIMO ANALÝZY
    </span>
</template>
