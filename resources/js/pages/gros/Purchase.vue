<script setup lang="ts">
import Card from '@/components/gros/Card.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Horizon {
    years: number;
    year: number;
    value: number;
}
interface Result {
    ok: boolean;
    purchase: {
        amount: number;
        recurring: boolean;
        horizons: Horizon[];
        freedom_if_saved: number | null;
        freedom_if_spent: number | null;
        delay_years: number | null;
    } | null;
    engine: { label: string; real_cagr: number } | null;
    context: { income_share: number | null; income_days: number | null; surplus_months: number | null };
}

defineProps<{
    context: {
        real_return: number;
        retire_year: number;
        monthly_income: number;
        monthly_surplus: number;
        monthly_contribution: number;
    };
}>();

const { eur, num, grad, primary, primarySoft } = useGros();

const amount = ref<number | null>(null);
const recurring = ref(false);
const result = ref<Result | null>(null);
const loading = ref(false);

let timer: ReturnType<typeof setTimeout> | undefined;

async function calculate() {
    if (!amount.value || amount.value <= 0) {
        result.value = null;
        return;
    }
    loading.value = true;
    const q = new URLSearchParams({ amount: String(amount.value), recurring: recurring.value ? '1' : '0' });
    try {
        const r = await fetch('/purchase/calculate?' + q.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        result.value = await r.json();
    } catch {
        result.value = null;
    } finally {
        loading.value = false;
    }
}

watch([amount, recurring], () => {
    clearTimeout(timer);
    timer = setTimeout(calculate, 400);
});

const purchase = computed(() => result.value?.purchase ?? null);

/** Najdlhší horizont je ten, ktorý bolí najviac — ten patrí do hlavičky. */
const headline = computed(() => {
    const h = purchase.value?.horizons ?? [];
    return h.length ? h[h.length - 1] : null;
});

/** Rýchle sumy, aby sa nemuselo písať. */
const presets = [50, 100, 300, 500, 1000, 2000];
</script>

<template>
    <Head title="Oplatí sa?" />
    <GrosLayout title="Oplatí sa?" subtitle="Koľko ťa nákup naozaj stojí — v eurách aj v čase">
        <div class="gros-rise">
            <!-- ── Vstup ───────────────────────────────────────────────── -->
            <Card>
                <label style="font-size: 12px; font-weight: 700; color: #8a8c9a; display: block; margin-bottom: 8px">
                    Koľko to stojí?
                </label>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                    <input
                        v-model.number="amount"
                        type="number"
                        min="0"
                        step="10"
                        placeholder="0"
                        autofocus
                        class="font-display"
                        style="flex: 1; min-width: 180px; background: #f7f6f2; border: 1.5px solid #eceae2; border-radius: 14px; padding: 16px 18px; font-size: 30px; font-weight: 800; letter-spacing: -1px; color: #20212e; outline: none"
                    />
                    <div style="display: flex; background: #f7f6f2; border-radius: 12px; padding: 3px">
                        <button
                            v-for="opt in [{ k: false, l: 'Jednorazovo' }, { k: true, l: 'Mesačne' }]"
                            :key="String(opt.k)"
                            type="button"
                            style="font-size: 13px; font-weight: 700; padding: 10px 15px; border-radius: 10px; white-space: nowrap"
                            :style="recurring === opt.k ? { background: '#fff', color: '#20212e', boxShadow: '0 2px 6px rgba(60,55,40,0.08)' } : { color: '#9a9cab' }"
                            @click="recurring = opt.k"
                        >
                            {{ opt.l }}
                        </button>
                    </div>
                </div>

                <div style="display: flex; flex-wrap: wrap; gap: 7px; margin-top: 12px">
                    <button
                        v-for="p in presets"
                        :key="p"
                        type="button"
                        style="font-size: 12.5px; font-weight: 700; padding: 8px 13px; border-radius: 10px; background: #f7f6f2; color: #6a6c7a"
                        @click="amount = p"
                    >
                        {{ eur(p) }}
                    </button>
                </div>
            </Card>

            <!-- ── Výsledok ────────────────────────────────────────────── -->
            <template v-if="amount && amount > 0">
                <div
                    v-if="headline"
                    style="border-radius: 20px; padding: 26px; color: #fff; margin-top: 14px"
                    :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}`, opacity: loading ? 0.6 : 1, transition: 'opacity .2s' }"
                >
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">
                        <template v-if="recurring">{{ eur(amount) }} mesačne by do roku {{ headline.year }} bolo</template>
                        <template v-else>{{ eur(amount) }} by do roku {{ headline.year }} bolo</template>
                    </div>
                    <div class="font-display" style="font-weight: 800; font-size: 40px; letter-spacing: -1.6px; margin-top: 6px">
                        {{ eur(headline.value) }}
                    </div>
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9; margin-top: 4px">
                        v dnešných eurách, pri reálnom výnose {{ num(context.real_return, 1) }} % ročne po inflácii
                    </div>

                    <div
                        v-if="purchase?.delay_years !== null && purchase?.delay_years !== undefined && purchase.delay_years > 0"
                        style="display: flex; align-items: center; gap: 10px; background: rgba(255, 255, 255, 0.16); border-radius: 13px; padding: 13px 16px; margin-top: 18px"
                    >
                        <span style="font-size: 18px">⏳</span>
                        <span style="font-size: 13.5px; font-weight: 700; line-height: 1.5">
                            Sloboda by prišla o {{ purchase.delay_years }} {{ purchase.delay_years === 1 ? 'rok' : purchase.delay_years < 5 ? 'roky' : 'rokov' }} neskôr —
                            {{ purchase.freedom_if_spent }} namiesto {{ purchase.freedom_if_saved }}.
                        </span>
                    </div>
                </div>

                <div v-else-if="loading" style="background: #fff; border-radius: 20px; padding: 40px; text-align: center; color: #b0b2bd; font-weight: 600; font-size: 14px; margin-top: 14px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05)">
                    Počítam…
                </div>

                <!-- horizonty -->
                <Card v-if="purchase?.horizons.length" title="Za koľko rokov" style="margin-top: 14px">
                    <div style="display: flex; flex-direction: column; gap: 9px; margin-top: 16px">
                        <div
                            v-for="h in purchase.horizons"
                            :key="h.years"
                            style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; background: #f7f6f2; border-radius: 13px; padding: 13px 16px"
                        >
                            <span style="font-size: 13px; font-weight: 800; min-width: 74px">{{ h.years }} rokov</span>
                            <span style="font-size: 11.5px; font-weight: 600; color: #9a9cab">do {{ h.year }}</span>
                            <span class="font-display" style="margin-left: auto; font-weight: 800; font-size: 18px" :style="{ color: primary }">
                                {{ eur(h.value) }}
                            </span>
                        </div>
                    </div>
                </Card>

                <!-- mierka voči príjmu -->
                <Card v-if="result?.context && !recurring" title="Iná mierka" style="margin-top: 14px">
                    <div style="display: flex; flex-wrap: wrap; gap: 26px; margin-top: 16px">
                        <div v-if="result.context.income_days !== null">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Dní tvojho príjmu</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 3px">{{ num(result.context.income_days, 1) }}</div>
                        </div>
                        <div v-if="result.context.income_share !== null">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Z mesačného príjmu</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 3px">{{ num(result.context.income_share, 0) }} %</div>
                        </div>
                        <div v-if="result.context.surplus_months !== null">
                            <div style="font-size: 11.5px; font-weight: 700; color: #8a8c9a">Mesiacov tvojho prebytku</div>
                            <div class="font-display" style="font-weight: 800; font-size: 24px; margin-top: 3px">{{ num(result.context.surplus_months, 1) }}</div>
                        </div>
                    </div>
                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; line-height: 1.6; margin-top: 14px">
                        Merané z tvojich transakcií: príjem {{ eur(context.monthly_income) }}/mes., po bežných výdavkoch ti ostáva
                        {{ eur(context.monthly_surplus) }}.
                    </div>
                </Card>
            </template>

            <!-- ── Ako to čítať ────────────────────────────────────────── -->
            <Card title="Ako to čítať" style="margin-top: 14px">
                <div style="font-size: 12.5px; color: #6a6c7a; font-weight: 600; line-height: 1.7; margin-top: 12px">
                    Toto nemá od nákupu odhovárať. Väčšina vecí, ktoré si kúpiš, za to stojí — a peniaze, ktoré nikdy neminieš, sú na nič.
                    Zmyslom je vidieť cenu aj v druhej mene: <b>v čase</b>. Keď to potom aj tak kúpiš, vieš, že to naozaj chceš.
                    <br /><br />
                    Suma sa počíta z tej istej projekcie ako
                    <Link href="/retirement" style="font-weight: 800; text-decoration: underline; color: #6a6c7a">tvoj dôchodkový plán</Link> — teda
                    z reálnych historických výnosov, po odrátaní inflácie, poplatkov aj konzervatívnej zrážky. Nie je to zložené úročenie
                    na papieri, ale posun v tvojom vlastnom pláne.
                    <br /><br />
                    <b>Jednorazovo</b> zníži sumu, ktorú máš dnes v portfóliu. <b>Mesačne</b> zníži to, čo doň každý mesiac posielaš — preto
                    vyjde násobne drahšie, aj keď suma vyzerá malá.
                </div>
            </Card>
        </div>
    </GrosLayout>
</template>
