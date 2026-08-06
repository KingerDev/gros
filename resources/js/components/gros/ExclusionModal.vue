<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { nextTick, onMounted, ref } from 'vue';

const props = defineProps<{ transactionId: number; reason?: string | null }>();
const emit = defineEmits<{ close: [] }>();

const { primary, primarySoft } = useGros();

const form = useForm({ excluded_from_analytics: true, exclusion_reason: props.reason ?? '' });

const input = ref<HTMLInputElement | null>(null);
onMounted(() => nextTick(() => input.value?.focus()));

// Najčastejšie dôvody na jedno klepnutie
const presets = ['Preplatené firmou', 'Vrátené peniaze', 'Jednorazová výnimka', 'Chyba / duplicita', 'Peniaze za niekoho iného'];

function submit() {
    if (!form.exclusion_reason.trim()) return;
    form.patch(`/transactions/${props.transactionId}/exclusion`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal title="Vylúčiť z analýzy" @close="emit('close')">
        <div
            style="
                margin-bottom: 18px;
                padding: 12px 14px;
                background: #fff6e8;
                border-radius: 12px;
                font-size: 12.5px;
                font-weight: 600;
                color: #a06a1e;
                line-height: 1.5;
            "
        >
            Transakcia ostane v zozname aj v zostatku účtu, ale nebude sa rátať do analýz, prehľadu ani do rozpočtu svojej kategórie.
        </div>

        <label class="gros-label">Dôvod vylúčenia</label>
        <input
            ref="input"
            v-model="form.exclusion_reason"
            type="text"
            maxlength="191"
            placeholder="napr. Preplatené firmou"
            class="gros-input"
            @keyup.enter="submit"
        />
        <div v-if="form.errors.exclusion_reason" style="color: #e8544e; font-size: 12px; font-weight: 600; margin-top: 6px">
            Napíš dôvod vylúčenia.
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 7px; margin: 12px 0 24px">
            <button
                v-for="p in presets"
                :key="p"
                type="button"
                style="padding: 7px 11px; border-radius: 10px; background: #f1efe8; color: #61637a; font-size: 12.5px; font-weight: 700"
                @click="form.exclusion_reason = p"
            >
                {{ p }}
            </button>
        </div>

        <div style="display: flex; gap: 10px">
            <button
                type="button"
                style="flex: 1; font-weight: 800; font-size: 14px; padding: 15px 10px; border-radius: 14px; background: #f1efe8; color: #61637a"
                @click="emit('close')"
            >
                Zrušiť
            </button>
            <button
                type="button"
                style="flex: 1; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
                :style="{
                    background: primary,
                    boxShadow: `0 10px 22px ${primarySoft}`,
                    opacity: form.processing || !form.exclusion_reason.trim() ? 0.6 : 1,
                }"
                :disabled="form.processing || !form.exclusion_reason.trim()"
                @click="submit"
            >
                Vylúčiť
            </button>
        </div>
    </Modal>
</template>
