<script setup lang="ts">
import { useGros } from '@/composables/useGros';
import { Link, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import {
    ArrowRightLeft,
    CalendarClock,
    ChartColumnBig,
    Landmark,
    LayoutGrid,
    LineChart,
    LogOut,
    PieChart,
    Settings,
    TrendingUp,
    Wallet,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

defineProps<{ title: string; subtitle?: string }>();

const page = usePage();
const { grad, primary, primarySoft, eurS, summary, hexToRgba } = useGros();

const isDesktop = useMediaQuery('(min-width: 860px)');

const nav = [
    { label: 'Prehľad', href: '/dashboard', icon: LayoutGrid },
    { label: 'Analýzy', href: '/analytics', icon: ChartColumnBig },
    { label: 'Účty', href: '/accounts', icon: Wallet },
    { label: 'Transakcie', href: '/transactions', icon: ArrowRightLeft },
    { label: 'Investície', href: '/investments', icon: TrendingUp },
    { label: 'Predplatné', href: '/subscriptions', icon: CalendarClock },
    { label: 'Úvery', href: '/loans', icon: Landmark },
    { label: 'Medziročne', href: '/yoy', icon: LineChart },
    { label: 'Rozpočty', href: '/budgets', icon: PieChart },
];

const currentPath = computed(() => new URL(page.props.ziggy?.location ?? window.location.href).pathname);

function isActive(href: string): boolean {
    const p = currentPath.value;
    return p === href || p.startsWith(href + '/');
}

function navStyle(href: string) {
    const active = isActive(href);
    return {
        display: 'flex',
        alignItems: 'center',
        gap: '12px',
        width: '100%',
        borderRadius: '13px',
        padding: '11px 13px',
        fontSize: '14.5px',
        fontWeight: 650,
        textAlign: 'left' as const,
        transition: 'all .15s',
        color: active ? '#fff' : '#61637a',
        background: active ? primary.value : 'transparent',
        boxShadow: active ? `0 8px 18px ${hexToRgba(primary.value, 0.32)}` : 'none',
    };
}

function mNavStyle(href: string) {
    const active = isActive(href);
    return {
        flex: '0 0 auto',
        width: '68px',
        display: 'flex',
        flexDirection: 'column' as const,
        alignItems: 'center',
        justifyContent: 'center',
        gap: '3px',
        fontSize: '10.5px',
        fontWeight: 700,
        background: 'transparent',
        color: active ? primary.value : '#a3a5b2',
    };
}

// Flash toast
const toast = ref<string | null>(null);
let toastTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => page.props.flash as { success?: string; error?: string } | undefined,
    (flash) => {
        const msg = flash?.success || flash?.error;
        if (msg) {
            toast.value = msg;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => (toast.value = null), 2600);
        }
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <div class="gros-field" style="display: flex; min-height: 100vh; background: #f5f4ef; color: #20212e">
        <!-- Sidebar (desktop) -->
        <aside
            v-if="isDesktop"
            style="width: 252px; flex-shrink: 0; background: #fff; border-right: 1px solid #eceae2; padding: 24px 16px; display: flex; flex-direction: column; gap: 5px; position: sticky; top: 0; height: 100vh"
        >
            <div style="display: flex; align-items: center; gap: 11px; padding: 6px 8px 24px">
                <div
                    class="font-display"
                    style="width: 40px; height: 40px; border-radius: 13px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 22px"
                    :style="{ background: grad, boxShadow: `0 8px 18px ${primarySoft}` }"
                >
                    G
                </div>
                <div class="font-display" style="font-weight: 800; font-size: 22px; letter-spacing: -0.6px">Groš</div>
            </div>

            <Link v-for="item in nav" :key="item.href" :href="item.href" :style="navStyle(item.href)">
                <component :is="item.icon" :size="20" :stroke-width="2" />
                {{ item.label }}
            </Link>

            <div style="flex: 1"></div>

            <div
                v-if="summary"
                style="border-radius: 18px; padding: 17px 17px 16px; color: #fff"
                :style="{ background: grad, boxShadow: `0 14px 30px ${primarySoft}` }"
            >
                <div style="font-size: 12px; font-weight: 600; opacity: 0.85; letter-spacing: 0.2px">Čisté imanie</div>
                <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 5px">{{ eurS(summary.netWorth) }}</div>
                <div style="font-size: 12px; font-weight: 600; opacity: 0.85; margin-top: 6px; display: flex; align-items: center; gap: 5px">
                    <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #c7f9cc"></span>
                    {{ summary.accountCount }} účty · investície
                </div>
            </div>

            <div style="display: flex; gap: 8px; margin-top: 10px">
                <Link
                    href="/settings/preferences"
                    style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 7px; padding: 10px; border-radius: 12px; background: #f5f4ef; color: #61637a; font-size: 13px; font-weight: 700"
                >
                    <Settings :size="16" :stroke-width="2.2" /> Nastavenia
                </Link>
                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    style="display: flex; align-items: center; justify-content: center; padding: 10px 12px; border-radius: 12px; background: #f5f4ef; color: #61637a"
                    title="Odhlásiť sa"
                >
                    <LogOut :size="16" :stroke-width="2.2" />
                </Link>
            </div>
        </aside>

        <!-- Main -->
        <main style="flex: 1; min-width: 0; display: flex; flex-direction: column">
            <header style="display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 22px 30px 6px">
                <div style="display: flex; align-items: center; gap: 11px; min-width: 0">
                    <div
                        v-if="!isDesktop"
                        class="font-display"
                        style="width: 34px; height: 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 800; font-size: 19px"
                        :style="{ background: grad }"
                    >
                        G
                    </div>
                    <div v-if="isDesktop" style="min-width: 0">
                        <div class="font-display" style="font-weight: 800; font-size: 27px; letter-spacing: -0.7px; line-height: 1.1">{{ title }}</div>
                        <div v-if="subtitle" style="color: #9a9cab; font-size: 13px; font-weight: 500; margin-top: 2px">{{ subtitle }}</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px">
                    <Link
                        v-if="!isDesktop"
                        href="/settings/preferences"
                        style="width: 40px; height: 40px; border-radius: 12px; background: #fff; display: flex; align-items: center; justify-content: center; color: #61637a; box-shadow: 0 2px 8px rgba(60, 55, 40, 0.06)"
                    >
                        <Settings :size="18" :stroke-width="2.2" />
                    </Link>
                    <slot name="action" />
                </div>
            </header>

            <div style="padding: 14px 30px 40px; max-width: 1160px; width: 100%">
                <div v-if="!isDesktop" class="font-display" style="font-weight: 800; font-size: 25px; letter-spacing: -0.6px; margin: 2px 0 16px">{{ title }}</div>
                <slot />
                <div v-if="!isDesktop" style="height: 88px"></div>
            </div>
        </main>

        <!-- Bottom nav (mobile) -->
        <nav
            v-if="!isDesktop"
            style="position: fixed; bottom: 0; left: 0; right: 0; height: 74px; background: rgba(255, 255, 255, 0.94); backdrop-filter: blur(14px); border-top: 1px solid #eceae2; display: flex; align-items: stretch; justify-content: flex-start; padding: 6px 4px; z-index: 40; overflow-x: auto"
        >
            <Link v-for="item in nav" :key="item.href" :href="item.href" :style="mNavStyle(item.href)">
                <component :is="item.icon" :size="21" :stroke-width="2" />
                <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%">{{ item.label }}</span>
            </Link>
        </nav>

        <!-- Flash toast -->
        <Transition name="gros-toast">
            <div
                v-if="toast"
                style="position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #20212e; color: #fff; font-weight: 600; font-size: 14px; padding: 12px 20px; border-radius: 14px; box-shadow: 0 12px 30px rgba(20, 18, 30, 0.3); z-index: 80"
            >
                {{ toast }}
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.gros-toast-enter-active,
.gros-toast-leave-active {
    transition: all 0.3s ease;
}
.gros-toast-enter-from,
.gros-toast-leave-to {
    opacity: 0;
    transform: translate(-50%, 12px);
}
</style>
