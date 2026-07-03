<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import SubscriptionModal from '@/components/gros/SubscriptionModal.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Subscription {
    id: number;
    name: string;
    amount: number | string;
    cycle: string;
    next_payment: string;
    color: string;
}

defineProps<{
    subscriptions: Subscription[];
    totals: { monthly: number; yearly: number; count: number };
}>();

const { eur, grad, primary, primarySoft, hexToRgba, formatDate } = useGros();

const modalOpen = ref(false);
const editSub = ref<Subscription | null>(null);

function openNew() {
    editSub.value = null;
    modalOpen.value = true;
}
function openEdit(s: Subscription) {
    editSub.value = s;
    modalOpen.value = true;
}
function cancel(s: Subscription) {
    router.delete(`/subscriptions/${s.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Predplatné" />
    <GrosLayout title="Predplatné" subtitle="Opakované platby">
        <template #action>
            <AddButton label="Pridať predplatné" @click="openNew" />
        </template>

        <div class="gros-rise">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px">
                <div style="border-radius: 20px; padding: 22px; color: #fff" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Mesačne</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">{{ eur(totals.monthly) }}</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 13px; font-weight: 600; color: #8a8c9a">Ročne</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">{{ eur(totals.yearly) }}</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 13px; font-weight: 600; color: #8a8c9a">Aktívne predplatné</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">{{ totals.count }}</div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Najbližšie platby</div>
                <button
                    type="button"
                    style="display: flex; align-items: center; gap: 6px; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 14px; border-radius: 11px; white-space: nowrap"
                    :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}` }"
                    @click="openNew"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Pridať predplatné
                </button>
            </div>

            <div style="background: #fff; border-radius: 20px; padding: 10px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                <div
                    v-for="s in subscriptions"
                    :key="s.id"
                    style="display: flex; align-items: center; gap: 13px; padding: 14px; border-radius: 14px; cursor: pointer"
                    @click="openEdit(s)"
                >
                    <span style="width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; flex-shrink: 0" :style="{ background: hexToRgba(s.color, 0.14), color: s.color }">{{ s.name[0] }}</span>
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 14.5px; font-weight: 700">{{ s.name }}</div>
                        <div style="font-size: 12px; color: #9a9cab; font-weight: 500">Ďalšia platba {{ formatDate(s.next_payment) }}</div>
                    </div>
                    <span style="font-size: 11px; font-weight: 700; color: #6a6c7a; background: #f1efe8; padding: 4px 9px; border-radius: 20px">{{ s.cycle === 'monthly' ? 'mesačne' : 'ročne' }}</span>
                    <div style="font-size: 15px; font-weight: 800; width: 78px; text-align: right">{{ eur(Number(s.amount)) }}</div>
                    <button
                        type="button"
                        style="width: 34px; height: 34px; border-radius: 11px; background: #f1efe8; color: #9a9cab; display: flex; align-items: center; justify-content: center; flex-shrink: 0"
                        title="Zrušiť predplatné"
                        @click.stop="cancel(s)"
                    >
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" /></svg>
                    </button>
                </div>
                <div v-if="!subscriptions.length" style="padding: 40px 16px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">Zatiaľ žiadne predplatné</div>
            </div>
        </div>

        <SubscriptionModal v-if="modalOpen" :subscription="editSub" @close="modalOpen = false" />
    </GrosLayout>
</template>
