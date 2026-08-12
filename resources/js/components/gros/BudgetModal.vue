<script setup lang="ts">
import Modal from '@/components/gros/Modal.vue';
import { useGros } from '@/composables/useGros';
import { useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';

interface BudgetEdit {
    id: number;
    category_id: number;
    limit_amount: number | string;
    period: string;
    spent?: number;
}

interface BudgetTxn {
    id: number;
    category_id: number;
    date: string;
    note: string | null;
    amount: number;
    refunded: number;
}

const props = defineProps<{ budget?: BudgetEdit | null }>();
const emit = defineEmits<{ close: [] }>();

const { categoryTree, primary, primarySoft, eur, catColor, catName, catGlyph, hexToRgba, formatDate } = useGros();
const editing = computed(() => !!props.budget);

const txns = ref<BudgetTxn[]>([]);
const txnsLoading = ref(false);
const txnsTotal = computed(() => txns.value.reduce((s, t) => s + t.amount, 0));

const periodLabels: Record<string, string> = { week: 'tento týždeň', month: 'tento mesiac', year: 'tento rok' };

onMounted(async () => {
    if (!props.budget) return;
    txnsLoading.value = true;
    try {
        const r = await fetch(`/budgets/${props.budget.id}/transactions`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        txns.value = (await r.json()).transactions ?? [];
    } finally {
        txnsLoading.value = false;
    }
});

// Skupiny výdavkov s ich listami (na optgroupy)
const expenseGroups = computed(() => categoryTree.value.filter((g) => g.type === 'expense'));
const firstLeaf = computed(() => {
    for (const g of expenseGroups.value) {
        if (g.children.length) return g.children[0].id;
        return g.id;
    }
    return null;
});

const form = useForm<{ category_id: number | null; limit_amount: string; period: string }>({
    category_id: props.budget?.category_id ?? firstLeaf.value,
    limit_amount: props.budget ? String(props.budget.limit_amount).replace('.', ',') : '',
    period: props.budget?.period ?? 'month',
});

function segStyle(v: string) {
    const active = form.period === v;
    return {
        flex: '1',
        padding: '11px',
        borderRadius: '10px',
        fontSize: '14px',
        fontWeight: 700,
        color: active ? '#20212e' : '#8a8c9a',
        background: active ? '#fff' : 'transparent',
        boxShadow: active ? '0 2px 8px rgba(60,55,40,.08)' : 'none',
    };
}

function submit() {
    form.transform((d) => ({ ...d, limit_amount: parseFloat(String(d.limit_amount).replace(/\s/g, '').replace(',', '.')) || 0 })).submit(
        editing.value ? 'put' : 'post',
        editing.value ? `/budgets/${props.budget!.id}` : '/budgets',
        {
            preserveScroll: true,
            onSuccess: () => emit('close'),
        },
    );
}

function destroy() {
    if (props.budget) form.delete(`/budgets/${props.budget.id}`, { preserveScroll: true, onSuccess: () => emit('close') });
}
</script>

<template>
    <Modal :title="editing ? 'Upraviť rozpočet' : 'Nový rozpočet'" @close="emit('close')">
        <div style="margin-bottom: 16px">
            <label class="gros-label">Kategória</label>
            <select v-model="form.category_id" class="gros-select">
                <template v-for="g in expenseGroups" :key="g.id">
                    <optgroup v-if="g.children.length" :label="(g.icon ? g.icon + ' ' : '') + g.name">
                        <option :value="g.id">{{ (g.icon ? g.icon + ' ' : '') + g.name }} — celá skupina</option>
                        <option v-for="c in g.children" :key="c.id" :value="c.id">{{ (c.icon ? c.icon + ' ' : '') + c.name }}</option>
                    </optgroup>
                    <option v-else :value="g.id">{{ (g.icon ? g.icon + ' ' : '') + g.name }}</option>
                </template>
            </select>
            <div style="font-size: 11.5px; color: #b0b2bd; font-weight: 600; margin-top: 6px">
                Rozpočet na celú skupinu počíta výdavky zo všetkých jej podkategórií.
            </div>
        </div>
        <label class="gros-label">Limit</label>
        <div class="gros-amount-wrap" style="margin-bottom: 16px">
            <input v-model="form.limit_amount" type="text" inputmode="decimal" placeholder="0,00" class="gros-amount" />
            <span class="font-display" style="font-weight: 800; font-size: 22px; color: #b8b6ac">€</span>
        </div>
        <label class="gros-label">Obdobie</label>
        <div style="display: flex; gap: 8px; background: #f1efe8; padding: 4px; border-radius: 13px; margin-bottom: 24px">
            <button type="button" :style="segStyle('week')" @click="form.period = 'week'">Týždeň</button>
            <button type="button" :style="segStyle('month')" @click="form.period = 'month'">Mesiac</button>
            <button type="button" :style="segStyle('year')" @click="form.period = 'year'">Rok</button>
        </div>
        <div style="display: flex; gap: 10px">
            <button
                v-if="editing"
                type="button"
                style="
                    flex-shrink: 0;
                    background: #fdeaea;
                    color: #e8544e;
                    font-weight: 800;
                    font-size: 15px;
                    padding: 15px 18px;
                    border-radius: 14px;
                "
                @click="destroy"
            >
                <svg
                    width="17"
                    height="17"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
                </svg>
            </button>
            <button
                type="button"
                style="flex: 1; color: #fff; font-weight: 800; font-size: 15px; padding: 15px; border-radius: 14px"
                :style="{ background: primary, boxShadow: `0 10px 22px ${primarySoft}`, opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="submit"
            >
                {{ editing ? 'Uložiť zmeny' : 'Pridať rozpočet' }}
            </button>
        </div>

        <!-- Transakcie, z ktorých sa skladá vyčerpaná suma -->
        <template v-if="editing">
            <div style="height: 1px; background: #f1efe8; margin: 22px 0 16px"></div>
            <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 10px">
                <div style="font-size: 13px; font-weight: 700; color: #6a6c7a">
                    Vyčerpané — {{ periodLabels[budget!.period] }}
                    <span v-if="txns.length" style="color: #b0b2bd; font-weight: 600">({{ txns.length }})</span>
                </div>
                <div class="font-display" style="font-weight: 800; font-size: 15px">{{ eur(txnsTotal) }}</div>
            </div>

            <div v-if="txnsLoading" style="padding: 18px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 13px">Načítavam…</div>
            <div v-else-if="!txns.length" style="color: #b0b2bd; font-weight: 600; font-size: 13px; padding: 8px 0">
                Za toto obdobie zatiaľ žiadne výdavky.
            </div>
            <div v-else style="display: flex; flex-direction: column; gap: 2px; max-height: 260px; overflow-y: auto">
                <div v-for="t in txns" :key="t.id" style="display: flex; align-items: center; gap: 10px; padding: 8px 6px; border-radius: 11px">
                    <span
                        style="
                            width: 30px;
                            height: 30px;
                            border-radius: 10px;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            font-size: 13px;
                            flex-shrink: 0;
                        "
                        :style="{ background: hexToRgba(catColor(t.category_id), 0.14), color: catColor(t.category_id) }"
                        >{{ catGlyph(t.category_id) }}</span
                    >
                    <div style="flex: 1; min-width: 0">
                        <div style="font-size: 13.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                            {{ t.note || catName(t.category_id) }}
                        </div>
                        <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600">
                            {{ formatDate(t.date) }}
                            <span v-if="t.refunded > 0"> · vrátené {{ eur(t.refunded) }}</span>
                        </div>
                    </div>
                    <div class="font-display" style="font-size: 13.5px; font-weight: 800; white-space: nowrap">{{ eur(t.amount) }}</div>
                </div>
            </div>
        </template>
    </Modal>
</template>
