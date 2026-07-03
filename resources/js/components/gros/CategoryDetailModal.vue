<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { computed, onMounted, ref } from 'vue';

const props = defineProps<{ categoryId: number; name: string }>();
const emit = defineEmits<{ close: [] }>();

const { eur, catColor, catGlyph, hexToRgba, formatDate } = useGros();

interface Detail {
    monthly: { label: string; amount: number }[];
    top: { amount: number; note: string | null; date: string }[];
    total: number;
    count: number;
    avg: number;
}
const data = ref<Detail | null>(null);
const loading = ref(true);

const color = computed(() => catColor(props.categoryId));
const trendMax = computed(() => Math.max(1, ...(data.value?.monthly.map((m) => m.amount) ?? [1])));

onMounted(async () => {
    try {
        const r = await fetch(`/analytics/category?category_id=${props.categoryId}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        data.value = await r.json();
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Modal :title="name" @close="emit('close')">
        <div style="display: flex; align-items: center; gap: 12px; margin-top: -8px; margin-bottom: 16px">
            <span style="width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0" :style="{ background: hexToRgba(color, 0.16), color }">{{ catGlyph(categoryId) }}</span>
            <div v-if="data" style="display: flex; gap: 18px">
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Spolu</div><div class="font-display" style="font-size: 18px; font-weight: 800">{{ eur(data.total) }}</div></div>
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Počet</div><div class="font-display" style="font-size: 18px; font-weight: 800">{{ data.count }}</div></div>
                <div><div style="font-size: 11px; color: #9a9cab; font-weight: 600">Priemer</div><div class="font-display" style="font-size: 18px; font-weight: 800">{{ eur(data.avg) }}</div></div>
            </div>
        </div>

        <div v-if="loading" style="padding: 30px; text-align: center; color: #b0b2bd; font-weight: 600">Načítavam…</div>

        <template v-else-if="data">
            <!-- Vývoj po mesiacoch -->
            <div style="font-size: 13px; font-weight: 700; color: #6a6c7a; margin-bottom: 10px">Posledných 12 mesiacov</div>
            <div style="display: flex; align-items: flex-end; gap: 5px; height: 90px; margin-bottom: 20px">
                <div v-for="(m, i) in data.monthly" :key="i" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 5px; height: 100%; justify-content: flex-end">
                    <div :title="eur(m.amount)" :style="{ width: '100%', maxWidth: '18px', height: (m.amount / trendMax) * 100 + '%', minHeight: m.amount > 0 ? '3px' : '0', background: color, borderRadius: '4px 4px 0 0' }"></div>
                    <div style="font-size: 9px; font-weight: 700; color: #9a9cab; white-space: nowrap">{{ m.label }}</div>
                </div>
            </div>

            <!-- Top transakcie -->
            <div style="font-size: 13px; font-weight: 700; color: #6a6c7a; margin-bottom: 8px">Najväčšie transakcie</div>
            <div style="display: flex; flex-direction: column; gap: 4px">
                <div v-for="(t, i) in data.top" :key="i" style="display: flex; align-items: center; gap: 10px; padding: 9px 8px; border-radius: 11px">
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 13.5px; font-weight: 700">{{ t.note || name }}</div>
                        <div style="font-size: 11.5px; color: #9a9cab; font-weight: 500">{{ formatDate(t.date) }}</div>
                    </div>
                    <div style="font-size: 13.5px; font-weight: 800">{{ eur(t.amount) }}</div>
                </div>
                <div v-if="!data.top.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px; padding: 8px 0">Žiadne transakcie.</div>
            </div>
        </template>
    </Modal>
</template>
