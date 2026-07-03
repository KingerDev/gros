<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import LoanModal from '@/components/gros/LoanModal.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Loan {
    id: number;
    kind: string;
    name: string;
    balance: number | string;
    principal: number | string;
    payment: number | string;
    rate: number | string;
    next_payment: string;
    color: string;
}

const props = defineProps<{
    loans: Loan[];
    totals: { owed: number; lent: number; monthlyPayment: number };
}>();

const { eur, num, primary, primarySoft, hexToRgba, formatDate } = useGros();

const modalOpen = ref(false);
const editLoan = ref<Loan | null>(null);

const sortedLoans = computed(() =>
    [...props.loans].sort((a, b) => (a.kind === b.kind ? (a.next_payment < b.next_payment ? -1 : 1) : a.kind === 'owe' ? -1 : 1)),
);

function openNew() {
    editLoan.value = null;
    modalOpen.value = true;
}
function openEdit(l: Loan) {
    editLoan.value = l;
    modalOpen.value = true;
}

function paidPct(l: Loan): number {
    const p = Number(l.principal);
    return p > 0 ? Math.max(0, Math.min(100, ((p - Number(l.balance)) / p) * 100)) : 0;
}
function showProgress(l: Loan): boolean {
    return l.kind === 'owe' && Number(l.principal) > Number(l.balance);
}
</script>

<template>
    <Head title="Úvery" />
    <GrosLayout title="Úvery" subtitle="Dlhy a požičané peniaze">
        <template #action>
            <AddButton label="Pridať úver" @click="openNew" />
        </template>

        <div class="gros-rise">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px">
                <div style="background: linear-gradient(135deg, #e8544e, #f0692a); border-radius: 20px; padding: 22px; color: #fff; box-shadow: 0 16px 34px rgba(232, 84, 78, 0.32)">
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Celkovo dlžím</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">{{ eur(totals.owed) }}</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 13px; font-weight: 600; color: #8a8c9a">Požičal som</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px; color: #2ba35a">{{ eur(totals.lent) }}</div>
                </div>
                <div style="background: #fff; border-radius: 20px; padding: 22px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 13px; font-weight: 600; color: #8a8c9a">Mesačné splátky</div>
                    <div class="font-display" style="font-weight: 800; font-size: 30px; letter-spacing: -1px; margin-top: 8px">{{ eur(totals.monthlyPayment) }}</div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Prehľad úverov</div>
                <button
                    type="button"
                    style="display: flex; align-items: center; gap: 6px; color: #fff; font-weight: 700; font-size: 13px; padding: 9px 14px; border-radius: 11px; white-space: nowrap"
                    :style="{ background: primary, boxShadow: `0 6px 14px ${primarySoft}` }"
                    @click="openNew"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Pridať úver
                </button>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px">
                <div
                    v-for="l in sortedLoans"
                    :key="l.id"
                    style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); position: relative; overflow: hidden; cursor: pointer"
                    @click="openEdit(l)"
                >
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 5px" :style="{ background: l.color }"></div>
                    <div style="display: flex; align-items: center; gap: 12px">
                        <span style="width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px; flex-shrink: 0" :style="{ background: hexToRgba(l.color, 0.14), color: l.color }">{{ l.name[0] }}</span>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 15px; font-weight: 700">{{ l.name }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ l.kind === 'owe' ? 'Dlžím' : 'Požičal som' }}</div>
                        </div>
                        <span style="font-size: 11px; font-weight: 700; padding: 4px 9px; border-radius: 20px" :style="{ color: l.color, background: hexToRgba(l.color, 0.14) }">{{ Number(l.rate) ? num(Number(l.rate), 1) + ' % p.a.' : 'bez úroku' }}</span>
                    </div>
                    <div style="display: flex; align-items: flex-end; justify-content: space-between; margin-top: 16px">
                        <div>
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">{{ l.kind === 'owe' ? 'Zostáva splatiť' : 'Má mi vrátiť' }}</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; letter-spacing: -0.7px; margin-top: 2px">{{ eur(Number(l.balance)) }}</div>
                        </div>
                        <div style="text-align: right">
                            <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">Mes. splátka</div>
                            <div style="font-size: 15px; font-weight: 800; margin-top: 2px">{{ Number(l.payment) ? eur(Number(l.payment)) : '—' }}</div>
                        </div>
                    </div>
                    <template v-if="showProgress(l)">
                        <div style="height: 8px; background: #f1efe8; border-radius: 5px; overflow: hidden; margin-top: 14px">
                            <div :style="{ height: '100%', width: paidPct(l).toFixed(1) + '%', background: l.color, borderRadius: '5px' }"></div>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 11.5px; font-weight: 600; color: #9a9cab; margin-top: 7px">
                            <span>Splatené {{ num(paidPct(l)) }}%</span>
                            <span>z {{ eur(Number(l.principal)) }}</span>
                        </div>
                    </template>
                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 12px; display: flex; align-items: center; gap: 6px">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="4" y="5" width="16" height="16" rx="3" /><path d="M4 10h16M9 3v4M15 3v4" /></svg>
                        {{ (l.kind === 'owe' ? 'Ďalšia splátka ' : 'Termín vrátenia ') + formatDate(l.next_payment) }}
                    </div>
                </div>

                <button
                    type="button"
                    style="border: 2px dashed #dcdace; border-radius: 20px; padding: 20px; background: transparent; color: #9a9cab; font-weight: 700; font-size: 14px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 150px"
                    @click="openNew"
                >
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Pridať úver
                </button>
            </div>
        </div>

        <LoanModal v-if="modalOpen" :loan="editLoan" @close="modalOpen = false" />
    </GrosLayout>
</template>
