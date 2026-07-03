<script setup lang="ts">
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { gradient } from '@/lib/gros';

const { ref: gref, settings } = useGros();

const form = useForm({
    accent: settings.value.accent,
    show_decimals: settings.value.showDecimals,
    privacy_mode: settings.value.privacyMode,
});

function save() {
    form.put('/settings/preferences', { preserveScroll: true });
}

const cardShadow = '0 4px 18px rgba(60,55,40,.05)';
</script>

<template>
    <Head title="Nastavenia" />
    <GrosLayout title="Nastavenia" subtitle="Vzhľad a súkromie">
        <div class="gros-rise" style="max-width: 640px">
            <!-- Accent -->
            <div style="background: #fff; border-radius: 20px; padding: 24px" :style="{ boxShadow: cardShadow }">
                <div class="font-display" style="font-weight: 700; font-size: 17px; letter-spacing: -0.3px">Akcentová farba</div>
                <div style="font-size: 13px; color: #8a8c9a; font-weight: 500; margin-top: 4px">Ladí gradienty, tlačidlá a zvýraznenia v celej aplikácii.</div>
                <div style="display: flex; gap: 12px; margin-top: 18px; flex-wrap: wrap">
                    <button
                        v-for="c in gref.accentOptions"
                        :key="c"
                        type="button"
                        style="width: 48px; height: 48px; border-radius: 15px; cursor: pointer; position: relative; border: 3px solid transparent"
                        :style="{ background: gradient(c), borderColor: form.accent === c ? '#20212e' : 'transparent' }"
                        @click="form.accent = c"
                    >
                        <svg v-if="form.accent === c" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="position: absolute; inset: 0; margin: auto"><path d="M20 6L9 17l-5-5" /></svg>
                    </button>
                </div>
            </div>

            <!-- Toggles -->
            <div style="background: #fff; border-radius: 20px; padding: 8px; margin-top: 14px" :style="{ boxShadow: cardShadow }">
                <button type="button" style="display: flex; align-items: center; gap: 14px; padding: 16px; width: 100%; text-align: left; border-radius: 14px" @click="form.show_decimals = !form.show_decimals">
                    <div style="flex: 1">
                        <div style="font-size: 15px; font-weight: 700">Zobrazovať desatinné miesta</div>
                        <div style="font-size: 12.5px; color: #8a8c9a; font-weight: 500; margin-top: 2px">Napr. 1 234,56 € namiesto 1 235 €.</div>
                    </div>
                    <span style="width: 46px; height: 28px; border-radius: 20px; flex-shrink: 0; transition: background 0.2s; position: relative" :style="{ background: form.show_decimals ? '#2ba35a' : '#dcdace' }">
                        <span style="position: absolute; top: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2)" :style="{ left: form.show_decimals ? '21px' : '3px' }"></span>
                    </span>
                </button>
                <div style="height: 1px; background: #f1efe8; margin: 0 16px"></div>
                <button type="button" style="display: flex; align-items: center; gap: 14px; padding: 16px; width: 100%; text-align: left; border-radius: 14px" @click="form.privacy_mode = !form.privacy_mode">
                    <div style="flex: 1">
                        <div style="font-size: 15px; font-weight: 700">Súkromný režim</div>
                        <div style="font-size: 12.5px; color: #8a8c9a; font-weight: 500; margin-top: 2px">Skryje všetky sumy ako ••••• €.</div>
                    </div>
                    <span style="width: 46px; height: 28px; border-radius: 20px; flex-shrink: 0; transition: background 0.2s; position: relative" :style="{ background: form.privacy_mode ? '#2ba35a' : '#dcdace' }">
                        <span style="position: absolute; top: 3px; width: 22px; height: 22px; border-radius: 50%; background: #fff; transition: left 0.2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2)" :style="{ left: form.privacy_mode ? '21px' : '3px' }"></span>
                    </span>
                </button>
            </div>

            <button
                type="button"
                style="margin-top: 16px; color: #fff; font-weight: 800; font-size: 15px; padding: 14px 26px; border-radius: 14px"
                :style="{ background: form.accent, opacity: form.processing ? 0.7 : 1 }"
                :disabled="form.processing"
                @click="save"
            >
                Uložiť nastavenia
            </button>

            <!-- Account links -->
            <div style="background: #fff; border-radius: 20px; padding: 8px; margin-top: 24px" :style="{ boxShadow: cardShadow }">
                <div class="font-display" style="font-weight: 700; font-size: 15px; padding: 14px 16px 6px">Účet</div>
                <Link href="/categories" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-radius: 12px; font-size: 14px; font-weight: 600">
                    Kategórie
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#c4c2ba" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
                </Link>
                <Link href="/settings/profile" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-radius: 12px; font-size: 14px; font-weight: 600">
                    Profil a e-mail
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#c4c2ba" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
                </Link>
                <Link href="/settings/password" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-radius: 12px; font-size: 14px; font-weight: 600">
                    Zmena hesla
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#c4c2ba" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6" /></svg>
                </Link>
                <Link href="/logout" method="post" as="button" style="display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-radius: 12px; font-size: 14px; font-weight: 700; color: #e8544e; width: 100%; text-align: left">
                    Odhlásiť sa
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" /></svg>
                </Link>
            </div>
        </div>
    </GrosLayout>
</template>
