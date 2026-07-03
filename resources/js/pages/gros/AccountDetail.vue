<script setup lang="ts">
import TransactionModal from '@/components/gros/TransactionModal.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

interface AccountRef {
    id: number;
    name: string;
    color: string;
}
interface Txn {
    id: number;
    type: string;
    category_id: number | null;
    amount: number | string;
    account_id: number;
    to_account_id: number | null;
    date: string;
    note: string | null;
    account: AccountRef | null;
    to_account: AccountRef | null;
}
interface Account {
    id: number;
    name: string;
    type: string;
    balance: number | string;
    color: string;
}

const props = defineProps<{
    account: Account;
    transactions: Txn[];
    accounts: { id: number; name: string }[];
    income: number;
    expense: number;
    net: number;
}>();

const { eur, eurS, gradient, soft, catName, catColor, catGlyph, hexToRgba, formatDate } = useGros();

// Smer prevodu voči tomuto účtu
function transferOut(t: Txn): boolean {
    return t.account_id === props.account.id;
}

const showModal = ref(false);
const editTxn = ref<Txn | null>(null);

function addHere() {
    editTxn.value = null;
    showModal.value = true;
}
function editRow(t: Txn) {
    editTxn.value = t;
    showModal.value = true;
}
</script>

<template>
    <Head :title="account.name" />
    <GrosLayout :title="account.name" :subtitle="account.type">
        <div class="gros-rise">
            <Link href="/accounts" style="display: flex; align-items: center; gap: 6px; color: #61637a; font-weight: 700; font-size: 13.5px; margin-bottom: 14px; width: fit-content">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6" /></svg>
                Späť na účty
            </Link>

            <div style="border-radius: 20px; padding: 24px 26px; color: #fff" :style="{ background: gradient(account.color), boxShadow: `0 16px 34px ${soft(account.color)}` }">
                <div style="display: flex; align-items: center; gap: 14px">
                    <span class="font-display" style="width: 52px; height: 52px; border-radius: 15px; background: rgba(255, 255, 255, 0.22); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 22px">{{ account.name[0] }}</span>
                    <div style="min-width: 0">
                        <div class="font-display" style="font-weight: 800; font-size: 23px; letter-spacing: -0.5px">{{ account.name }}</div>
                        <div style="font-size: 13px; font-weight: 600; opacity: 0.9">{{ account.type }}</div>
                    </div>
                </div>
                <div style="font-size: 13px; font-weight: 600; opacity: 0.9; margin-top: 18px">Aktuálny zostatok</div>
                <div class="font-display" style="font-weight: 800; font-size: 34px; letter-spacing: -1.2px; margin-top: 4px">{{ eur(Number(account.balance)) }}</div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(148px, 1fr)); gap: 14px; margin-top: 14px">
                <div style="background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Príjmy na účte</div>
                    <div class="font-display" style="font-weight: 800; font-size: 21px; color: #2ba35a; margin-top: 6px">{{ eur(income) }}</div>
                </div>
                <div style="background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Výdavky z účtu</div>
                    <div class="font-display" style="font-weight: 800; font-size: 21px; color: #e8544e; margin-top: 6px">{{ eur(expense) }}</div>
                </div>
                <div style="background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Zostatok toku</div>
                    <div class="font-display" style="font-weight: 800; font-size: 21px; margin-top: 6px" :style="{ color: net < 0 ? '#e8544e' : '#20212e' }">{{ eurS(net) }}</div>
                </div>
                <div style="background: #fff; border-radius: 18px; padding: 18px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    <div style="font-size: 12.5px; font-weight: 600; color: #8a8c9a">Transakcie</div>
                    <div class="font-display" style="font-weight: 800; font-size: 21px; margin-top: 6px">{{ transactions.length }}</div>
                </div>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; margin: 22px 2px 12px">
                <div class="font-display" style="font-weight: 700; font-size: 17px">Transakcie účtu</div>
                <button
                    type="button"
                    style="display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 13px; padding: 9px 13px; border-radius: 11px"
                    :style="{ background: hexToRgba(account.color, 0.14), color: account.color }"
                    @click="addHere"
                >
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Pridať sem
                </button>
            </div>

            <div style="background: #fff; border-radius: 20px; padding: 8px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                <div
                    v-for="t in transactions"
                    :key="t.id"
                    style="display: flex; align-items: center; gap: 13px; padding: 13px 14px; border-radius: 14px; cursor: pointer"
                    @click="editRow(t)"
                >
                    <!-- Prevod -->
                    <template v-if="t.type === 'transfer'">
                        <span style="width: 42px; height: 42px; border-radius: 13px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: #eef1f6; color: #64748b">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 8h13l-3-3M17 16H4l3 3" /></svg>
                        </span>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 14.5px; font-weight: 700">{{ t.note || 'Prevod' }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ transferOut(t) ? 'Na účet ' + (t.to_account?.name ?? '—') : 'Z účtu ' + (t.account?.name ?? '—') }} · {{ formatDate(t.date) }}</div>
                        </div>
                        <div style="font-size: 15px; font-weight: 800; white-space: nowrap" :style="{ color: transferOut(t) ? '#e8544e' : '#2ba35a' }">{{ transferOut(t) ? '− ' : '+ ' }}{{ eur(Number(t.amount)) }}</div>
                    </template>
                    <!-- Príjem / výdavok -->
                    <template v-else>
                        <span style="width: 42px; height: 42px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 16px; flex-shrink: 0" :style="{ background: hexToRgba(catColor(t.category_id), 0.14), color: catColor(t.category_id) }">{{ catGlyph(t.category_id) }}</span>
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 14.5px; font-weight: 700">{{ t.note || catName(t.category_id) }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ catName(t.category_id) }} · {{ formatDate(t.date) }}</div>
                        </div>
                        <div style="font-size: 15px; font-weight: 800; white-space: nowrap" :style="{ color: t.type === 'income' ? '#2ba35a' : '#e8544e' }">{{ t.type === 'income' ? '+ ' : '− ' }}{{ eur(Number(t.amount)) }}</div>
                    </template>
                </div>
                <div v-if="!transactions.length" style="padding: 40px 16px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px">Žiadne transakcie na tomto účte</div>
            </div>
        </div>

        <TransactionModal v-if="showModal" :accounts="accounts" :preset-account-id="account.id" :transaction="editTxn" @close="showModal = false" />
    </GrosLayout>
</template>
