<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';

// First version: a plain list. Live status (polling) and images come next.
interface GenerationRow {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';
    min_charge: string;
    max_charge: string;
    charge: string | null;
    error_message: string | null;
    created_at: string;
}

defineProps<{
    generations: { data: GenerationRow[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: route('generations.index') }];
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold">History</h1>
                <Button as-child><Link :href="route('generations.create')">New generation</Link></Button>
            </div>

            <p v-if="generations.data.length === 0" class="text-muted-foreground">No generations yet.</p>

            <ul class="flex flex-col gap-3">
                <li v-for="generation in generations.data" :key="generation.id" class="rounded-lg border p-4">
                    <p class="font-medium">{{ generation.prompt }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ generation.status }} · {{ generation.size }} · {{ generation.quality }} ·
                        <template v-if="generation.charge !== null">charged {{ generation.charge }}</template>
                        <template v-else>estimated {{ generation.min_charge }} – {{ generation.max_charge }}</template>
                    </p>
                    <p v-if="generation.error_message" class="text-sm text-red-600">{{ generation.error_message }}</p>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
