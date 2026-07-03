<script setup lang="ts">
import AccountModal from '@/components/gros/AccountModal.vue';
import AddButton from '@/components/gros/AddButton.vue';
import GrosLayout from '@/layouts/GrosLayout.vue';
import { useGros } from '@/composables/useGros';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Account {
    id: number;
    name: string;
    type: string;
    balance: number | string;
    color: string;
}

const props = defineProps<{ accounts: Account[]; total: number }>();

const { eur, num, grad, primarySoft, hexToRgba } = useGros();

const modalOpen = ref(false);
const editAccount = ref<Account | null>(null);
const totalOrOne = computed(() => props.total || 1);

function openNew() {
    editAccount.value = null;
    modalOpen.value = true;
}
function openEdit(a: Account) {
    editAccount.value = a;
    modalOpen.value = true;
}
function open(a: Account) {
    router.get(`/accounts/${a.id}`);
}
</script>

<template>
    <Head title="Účty" />
    <GrosLayout title="Účty" subtitle="Všetky tvoje účty na jednom mieste">
        <template #action>
            <AddButton label="Pridať účet" @click="openNew" />
        </template>

        <div class="gros-rise">
            <div style="border-radius: 20px; padding: 24px 26px; color: #fff; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px" :style="{ background: grad, boxShadow: `0 16px 34px ${primarySoft}` }">
                <div>
                    <div style="font-size: 13px; font-weight: 600; opacity: 0.9">Zostatok na účtoch spolu</div>
                    <div class="font-display" style="font-weight: 800; font-size: 34px; letter-spacing: -1.2px; margin-top: 6px">{{ eur(total) }}</div>
                </div>
                <div style="font-size: 13px; font-weight: 600; opacity: 0.9">{{ accounts.length }} aktívne účty</div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; margin-top: 14px">
                <div
                    v-for="a in accounts"
                    :key="a.id"
                    style="background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(60, 55, 40, 0.05); position: relative; overflow: hidden; cursor: pointer"
                    @click="open(a)"
                >
                    <div style="position: absolute; top: 0; left: 0; right: 0; height: 5px" :style="{ background: a.color }"></div>
                    <div style="display: flex; align-items: center; gap: 12px">
                        <span style="width: 44px; height: 44px; border-radius: 13px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 18px" :style="{ background: hexToRgba(a.color, 0.14), color: a.color }">{{ a.name[0] }}</span>
                        <div style="min-width: 0; flex: 1">
                            <div style="font-size: 15px; font-weight: 700">{{ a.name }}</div>
                            <div style="font-size: 12px; color: #9a9cab; font-weight: 500">{{ a.type }}</div>
                        </div>
                        <button
                            type="button"
                            style="width: 30px; height: 30px; border-radius: 9px; background: #f5f4ef; color: #9a9cab; display: flex; align-items: center; justify-content: center; flex-shrink: 0"
                            title="Upraviť"
                            @click.stop="openEdit(a)"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" /></svg>
                        </button>
                    </div>
                    <div class="font-display" style="font-weight: 800; font-size: 26px; letter-spacing: -0.8px; margin-top: 16px">{{ eur(Number(a.balance)) }}</div>
                    <div style="height: 7px; background: #f1efe8; border-radius: 5px; overflow: hidden; margin-top: 12px">
                        <div :style="{ height: '100%', width: (Number(a.balance) / totalOrOne) * 100 + '%', background: a.color, borderRadius: '5px' }"></div>
                    </div>
                    <div style="font-size: 11.5px; color: #9a9cab; font-weight: 600; margin-top: 7px">{{ num((Number(a.balance) / totalOrOne) * 100) }}% z celku</div>
                </div>

                <button
                    type="button"
                    style="border: 2px dashed #dcdace; border-radius: 20px; padding: 20px; background: transparent; color: #9a9cab; font-weight: 700; font-size: 14px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; min-height: 150px"
                    @click="openNew"
                >
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    Pridať účet
                </button>
            </div>
        </div>

        <AccountModal v-if="modalOpen" :account="editAccount" @close="modalOpen = false" />
    </GrosLayout>
</template>
