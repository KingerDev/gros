<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

interface RefundRow {
    id: number;
    amount: number | string;
    date: string;
    note: string | null;
    account_id: number;
}
interface Txn {
    id: number;
    type: string;
    category_id: number | null;
    account_id: number;
    amount: number | string;
    date: string;
    note: string | null;
    refund_for_id: number | null;
    refunds?: RefundRow[];
}

const props = defineProps<{
    transaction: Txn;
    accounts: { id: number; name: string }[];
    /** Všetky transakcie — z nich sa ponúkajú nespárované príjmy na spárovanie. */
    candidates: Txn[];
}>();
const emit = defineEmits<{ close: [] }>();

const { eur, primary, primarySoft, catName, formatDate } = useGros();

const tab = ref<'new' | 'pair'>('new');

const refunds = computed(() => props.transaction.refunds ?? []);
const refundedTotal = computed(() => refunds.value.reduce((s, r) => s + Number(r.amount), 0));
const remaining = computed(() => Math.max(0, Number(props.transaction.amount) - refundedTotal.value));

/** Nespárované príjmy, ktoré sa do zvyšku nákupu zmestia — kandidáti na vrátenie. */
const pairable = computed(() =>
    props.candidates.filter((t) => t.type === 'income' && !t.refund_for_id && Number(t.amount) <= remaining.value + 0.001).slice(0, 40),
);

const form = useForm({
    amount: '',
    account_id: props.transaction.account_id,
    date: new Date().toISOString().slice(0, 10),
    note: '',
});

const amountInput = ref<HTMLInputElement | null>(null);
onMounted(() => nextTick(() => amountInput.value?.focus()));

function fillRemaining() {
    form.amount = remaining.value.toFixed(2).replace('.', ',');
}

function submit() {
    form.transform((data) => ({ ...data, amount: parseFloat(String(data.amount).replace(/\s/g, '').replace(',', '.')) || 0 })).post(
        `/transactions/${props.transaction.id}/refunds`,
        {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        },
    );
}

function pair(t: Txn) {
    router.patch(
        `/transactions/${t.id}/refund-link`,
        { refund_for_id: props.transaction.id },
        { preserveScroll: true, onSuccess: () => emit('close') },
    );
}

function unpair(r: RefundRow) {
    router.patch(`/transactions/${r.id}/refund-link`, { refund_for_id: null }, { preserveScroll: true });
}

function tabStyle(v: string) {
    const active = tab.value === v;
    return {
        flex: '1',
        padding: '11px 6px',
        borderRadius: '10px',
        fontSize: '14px',
        fontWeight: 700,
        color: active ? '#20212e' : '#8a8c9a',
        background: active ? '#fff' : 'transparent',
        boxShadow: active ? '0 2px 8px rgba(60,55,40,.08)' : 'none',
    };
}
</script>

<template>
    <Modal title="Vrátenie peňazí" @close="emit('close')">
        <!-- Prehľad nákupu -->
        <div style="padding: 14px; border-radius: 14px; background: #faf9f5; border: 1.5px solid #eceae2; margin-bottom: 18px">
            <div style="font-size: 14.5px; font-weight: 700; color: #20212e">{{ transaction.note || catName(transaction.category_id) }}</div>
            <div style="font-size: 12px; color: #9a9cab; font-weight: 500; margin-top: 2px">
                {{ catName(transaction.category_id) }} · {{ formatDate(transaction.date) }}
            </div>
            <div style="display: flex; gap: 18px; margin-top: 11px; flex-wrap: wrap">
                <div>
                    <div style="font-size: 11px; font-weight: 700; color: #9a9cab; text-transform: uppercase">Nákup</div>
                    <div style="font-size: 15px; font-weight: 800">{{ eur(Number(transaction.amount)) }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; font-weight: 700; color: #9a9cab; text-transform: uppercase">Vrátené</div>
                    <div style="font-size: 15px; font-weight: 800; color: #2ba35a">{{ eur(refundedTotal) }}</div>
                </div>
                <div>
                    <div style="font-size: 11px; font-weight: 700; color: #9a9cab; text-transform: uppercase">Reálne minuté</div>
                    <div style="font-size: 15px; font-weight: 800" :style="{ color: primary }">
                        {{ eur(Number(transaction.amount) - refundedTotal) }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Už spárované vrátenia -->
        <div v-if="refunds.length" style="margin-bottom: 18px">
            <label class="gros-label">Spárované vrátenia</label>
            <div
                v-for="r in refunds"
                :key="r.id"
                style="
                    display: flex;
                    align-items: center;
                    gap: 10px;
                    padding: 10px 12px;
                    border-radius: 12px;
                    background: #eefaf1;
                    margin-bottom: 7px;
                "
            >
                <div style="flex: 1; min-width: 0">
                    <div style="font-size: 13.5px; font-weight: 700; color: #20212e">{{ r.note || 'Vrátenie' }}</div>
                    <div style="font-size: 11.5px; color: #7a8b7f; font-weight: 600">{{ formatDate(r.date) }}</div>
                </div>
                <div style="font-size: 14px; font-weight: 800; color: #2ba35a; white-space: nowrap">+ {{ eur(Number(r.amount)) }}</div>
                <button
                    type="button"
                    style="padding: 6px 10px; border-radius: 9px; background: #fff; color: #61637a; font-size: 12px; font-weight: 700; flex-shrink: 0"
                    title="Rozpárovať — ostane z toho bežný príjem"
                    @click="unpair(r)"
                >
                    Rozpárovať
                </button>
            </div>
        </div>

        <template v-if="remaining > 0">
            <div style="display: flex; gap: 6px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 18px">
                <button type="button" :style="tabStyle('new')" @click="tab = 'new'">Nové vrátenie</button>
                <button type="button" :style="tabStyle('pair')" @click="tab = 'pair'">Spárovať príjem</button>
            </div>

            <!-- Nové vrátenie -->
            <template v-if="tab === 'new'">
                <label class="gros-label">Vrátená suma</label>
                <div class="gros-amount-wrap" style="margin-bottom: 8px">
                    <input ref="amountInput" v-model="form.amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
                    <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
                </div>
                <button
                    type="button"
                    style="
                        padding: 7px 11px;
                        border-radius: 10px;
                        background: #f1efe8;
                        color: #61637a;
                        font-size: 12.5px;
                        font-weight: 700;
                        margin-bottom: 18px;
                    "
                    @click="fillRemaining"
                >
                    Celá zvyšná suma · {{ eur(remaining) }}
                </button>
                <div v-if="form.errors.amount" style="color: #e8544e; font-size: 12px; font-weight: 600; margin: -12px 0 14px">
                    {{ form.errors.amount }}
                </div>

                <div style="display: flex; gap: 12px; margin-bottom: 18px; flex-wrap: wrap">
                    <div style="flex: 1; min-width: 130px">
                        <label class="gros-label">Na účet</label>
                        <select v-model="form.account_id" class="gros-select">
                            <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                        </select>
                    </div>
                    <div style="flex: 1; min-width: 130px">
                        <label class="gros-label">Dátum</label>
                        <input v-model="form.date" type="date" class="gros-input" />
                    </div>
                </div>

                <div style="margin-bottom: 18px">
                    <label class="gros-label">Poznámka</label>
                    <input v-model="form.note" type="text" placeholder="napr. Vrátené tričko" class="gros-input" />
                </div>
            </template>

            <!-- Spárovanie existujúceho príjmu -->
            <template v-else>
                <div
                    style="
                        margin-bottom: 14px;
                        padding: 12px 14px;
                        background: #eef6ff;
                        border-radius: 12px;
                        font-size: 12.5px;
                        font-weight: 600;
                        color: #2a6ebd;
                        line-height: 1.5;
                    "
                >
                    Ak už máš príjem zapísaný (peniaze dorazili na účet), spáruj ho s týmto nákupom. Prestane sa rátať ako príjem a namiesto toho
                    zníži tento výdavok.
                </div>

                <div v-if="!pairable.length" style="padding: 26px 14px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 13.5px">
                    Žiadny nespárovaný príjem do {{ eur(remaining) }}
                </div>
                <button
                    v-for="c in pairable"
                    :key="c.id"
                    type="button"
                    style="
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        width: 100%;
                        text-align: left;
                        padding: 11px 12px;
                        border-radius: 12px;
                        background: #faf9f5;
                        border: 1.5px solid #eceae2;
                        margin-bottom: 7px;
                    "
                    @click="pair(c)"
                >
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 13.5px; font-weight: 700; color: #20212e">{{ c.note || catName(c.category_id) }}</div>
                        <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">{{ formatDate(c.date) }}</div>
                    </div>
                    <div style="font-size: 14px; font-weight: 800; color: #2ba35a; white-space: nowrap">+ {{ eur(Number(c.amount)) }}</div>
                </button>
            </template>
        </template>

        <div
            v-else
            style="padding: 14px; border-radius: 12px; background: #eefaf1; color: #2ba35a; font-size: 13px; font-weight: 700; margin-bottom: 18px"
        >
            Celý nákup je vrátený — v analýzach ťa nestál nič.
        </div>

        <div style="display: flex; gap: 10px">
            <button
                type="button"
                style="flex: 1; font-weight: 800; font-size: 14px; padding: 15px 10px; border-radius: 14px; background: #f1efe8; color: #61637a"
                @click="emit('close')"
            >
                Zavrieť
            </button>
            <button
                v-if="remaining > 0 && tab === 'new'"
                type="button"
                style="flex: 1; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
                :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="submit"
            >
                Zaznamenať vrátenie
            </button>
        </div>
    </Modal>
</template>
