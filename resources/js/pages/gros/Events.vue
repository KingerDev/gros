<script setup lang="ts">
import AddButton from '@/components/gros/AddButton.vue';
import AskAi from '@/components/gros/AskAi.vue';
import EventModal from '@/components/gros/EventModal.vue';
import ProgressBar from '@/components/gros/ProgressBar.vue';
import { useGros } from '@/composables/useGros';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface EventRow {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    days: number;
    budget: number | null;
    color: string;
    icon: string | null;
    note: string | null;
    total: number;
    count: number;
    per_day: number;
}

const props = defineProps<{ events: EventRow[] }>();

const { eur, hexToRgba, formatRange } = useGros();

const showModal = ref(false);

const today = new Date().toISOString().slice(0, 10);

/** Súčty po rokoch začiatku udalosti — koľko ročne ide na dovolenky a spol. */
const byYear = computed(() => {
    const m = new Map<string, { total: number; count: number }>();
    for (const e of props.events) {
        const y = e.starts_on.slice(0, 4);
        const row = m.get(y) ?? { total: 0, count: 0 };
        row.total += e.total;
        row.count += 1;
        m.set(y, row);
    }
    return [...m.entries()].map(([year, v]) => ({ year, ...v }));
});

function status(e: EventRow): string | null {
    if (e.starts_on > today) return 'Plánovaná';
    if (e.ends_on >= today) return 'Práve prebieha';
    return null;
}

function budgetPct(e: EventRow): number {
    return e.budget ? (e.total / e.budget) * 100 : 0;
}
</script>

<template>
    <Head title="Udalosti" />
    <GrosLayout title="Udalosti" subtitle="Dovolenky a iné jednorazové akcie — každá vo vlastnej bubline">
        <template #action>
            <AddButton label="Nová udalosť" @click="showModal = true" />
        </template>

        <div class="gros-rise">
            <div
                style="
                    background: #eef6ff;
                    color: #2a6ebd;
                    border-radius: 14px;
                    padding: 13px 16px;
                    font-size: 12.5px;
                    font-weight: 600;
                    line-height: 1.6;
                    margin-bottom: 14px;
                "
            >
                Výdavky priradené k udalosti si nechávajú svoju kategóriu (jedlo ostane jedlom), ale rátajú sa ako <b>jednorazové</b>: v súčtoch
                „koľko som minul" sú, no do priemerov, rezervy, dôchodku, rozpočtov kategórií ani anomálií nevstupujú.
            </div>

            <!-- Po rokoch -->
            <div v-if="byYear.length" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px">
                <div
                    v-for="y in byYear"
                    :key="y.year"
                    style="background: #fff; border-radius: 16px; padding: 14px 18px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); min-width: 140px"
                >
                    <div style="font-size: 12px; font-weight: 600; color: #8a8c9a">{{ y.year }} · {{ y.count }}× udalosť</div>
                    <div class="font-display" style="font-weight: 800; font-size: 22px; letter-spacing: -0.6px; margin-top: 4px">
                        {{ eur(y.total) }}
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px">
                <Link
                    v-for="e in events"
                    :key="e.id"
                    :href="`/events/${e.id}`"
                    style="
                        display: block;
                        background: #fff;
                        border-radius: 20px;
                        padding: 20px;
                        box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05);
                        color: #20212e;
                        border-top: 4px solid transparent;
                    "
                    :style="{ borderTopColor: e.color }"
                >
                    <div style="display: flex; align-items: center; gap: 12px">
                        <span
                            style="
                                width: 44px;
                                height: 44px;
                                border-radius: 13px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                font-size: 21px;
                                flex-shrink: 0;
                            "
                            :style="{ background: hexToRgba(e.color, 0.14), color: e.color }"
                            >{{ e.icon || e.name[0] }}</span
                        >
                        <div style="flex: 1; min-width: 0">
                            <div style="font-size: 15.5px; font-weight: 800; overflow: hidden; text-overflow: ellipsis; white-space: nowrap">
                                {{ e.name }}
                            </div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 600; margin-top: 2px">
                                {{ formatRange(e.starts_on, e.ends_on) }} · {{ e.days }} {{ e.days === 1 ? 'deň' : e.days < 5 ? 'dni' : 'dní' }}
                            </div>
                        </div>
                        <span
                            v-if="status(e)"
                            style="font-size: 10.5px; font-weight: 800; padding: 3px 8px; border-radius: 7px; white-space: nowrap"
                            :style="{ background: hexToRgba(e.color, 0.14), color: e.color }"
                            >{{ status(e) }}</span
                        >
                    </div>

                    <div style="display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; margin-top: 16px">
                        <div>
                            <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px">{{ eur(e.total) }}</div>
                            <div style="font-size: 12px; color: #8a8c9a; font-weight: 600; margin-top: 2px">
                                {{ eur(e.per_day) }} na deň · {{ e.count }}
                                {{ e.count === 1 ? 'transakcia' : e.count < 5 ? 'transakcie' : 'transakcií' }}
                            </div>
                        </div>
                    </div>

                    <div v-if="e.budget" style="margin-top: 14px">
                        <ProgressBar :pct="budgetPct(e)" :color="budgetPct(e) > 100 ? '#e8544e' : e.color" />
                        <div
                            style="font-size: 11.5px; font-weight: 700; margin-top: 6px"
                            :style="{ color: budgetPct(e) > 100 ? '#e8544e' : '#8a8c9a' }"
                        >
                            {{ Math.round(budgetPct(e)) }} % z rozpočtu {{ eur(e.budget) }}
                        </div>
                    </div>

                    <div
                        v-if="!e.count"
                        style="
                            margin-top: 14px;
                            font-size: 12px;
                            font-weight: 700;
                            color: #2a6ebd;
                            background: #eef6ff;
                            border-radius: 10px;
                            padding: 8px 11px;
                        "
                    >
                        Zatiaľ bez transakcií — otvor a priraď ich →
                    </div>
                </Link>
            </div>

            <div
                v-if="!events.length"
                style="
                    background: #fff;
                    border-radius: 20px;
                    padding: 44px 20px;
                    text-align: center;
                    box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05);
                    color: #9a9cab;
                    font-weight: 600;
                    font-size: 14px;
                    line-height: 1.7;
                "
            >
                <div style="font-size: 34px; margin-bottom: 8px">✈️</div>
                Zatiaľ žiadne udalosti.<br />
                Vytvor napríklad „Dovolenka Dublin 2026" a priraď k nej výdavky z cesty.
            </div>

            <AskAi
                v-if="events.length"
                style="margin-top: 14px"
                :questions="['Koľko ma stáli dovolenky tento rok?', 'Na čo som na poslednej dovolenke minul najviac?']"
            />
        </div>

        <EventModal v-if="showModal" @close="showModal = false" />
    </GrosLayout>
</template>
