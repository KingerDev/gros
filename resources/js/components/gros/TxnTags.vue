<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    source?: string | null;
    excluded?: boolean;
    reason?: string | null;
    /** Táto transakcia je vrátenie peňazí spárované s nejakým nákupom. */
    isRefund?: boolean;
    /** Z tohto nákupu sa už niečo vrátilo. */
    refundedAmount?: number;
    /** Pôvodná suma nákupu — rozlíši úplné vrátenie od čiastočného. */
    amount?: number;
}>();

const autoLabel = computed(() => (props.source === 'loan' ? 'Automatická splátka úveru' : 'Automatická platba predplatného'));

const refundedLabel = computed(() =>
    props.amount !== undefined && (props.refundedAmount ?? 0) >= props.amount - 0.001 ? 'VRÁTENÉ' : 'ČIASTOČNE VRÁTENÉ',
);
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
        v-if="isRefund"
        style="font-size: 10.5px; font-weight: 800; color: #2ba35a; background: #eefaf1; padding: 3px 7px; border-radius: 7px; white-space: nowrap"
        title="Vrátenie peňazí — znižuje pôvodný nákup, do príjmov sa neráta"
    >
        VRÁTENIE
    </span>
    <span
        v-else-if="refundedAmount"
        style="font-size: 10.5px; font-weight: 800; color: #2ba35a; background: #eefaf1; padding: 3px 7px; border-radius: 7px; white-space: nowrap"
        title="Časť tohto nákupu sa vrátila späť"
    >
        {{ refundedLabel }}
    </span>
    <span
        v-if="excluded"
        style="font-size: 10.5px; font-weight: 800; color: #a06a1e; background: #fff2dd; padding: 3px 7px; border-radius: 7px; white-space: nowrap"
        :title="reason ?? ''"
    >
        MIMO ANALÝZY
    </span>
</template>
