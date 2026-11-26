<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { watchDebounced } from '@vueuse/core';
import { Plus, X } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    sizes: { value: string; label: string }[];
    qualities: string[];
    maxPromptCharacters: number;
    maxReferenceImages: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: route('generations.create') }];

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

// Count characters the way the server does (Unicode code points, like PHP's mb_strlen),
// not UTF-16 units: "😀".length is 2, [..."😀"].length is 1.
const characters = computed(() => [...form.prompt].length);
const referenceUrls = computed(() => form.reference_images.map((url) => url.trim()).filter((url) => url !== ''));

// Validation errors for reference images arrive as "reference_images" or "reference_images.N".
const referenceErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, message]) => message),
);

function addReference() {
    if (form.reference_images.length < props.maxReferenceImages) {
        form.reference_images.push('');
    }
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

// Live price range from the server, so there is one pricing formula (the tested PHP one).
const estimate = ref<{ min: string; max: string } | null>(null);
const estimateError = ref<string | null>(null);
let pending: AbortController | null = null;

async function refreshEstimate() {
    pending?.abort(); // a slow older answer must not overwrite a newer one
    pending = new AbortController();

    try {
        const url = route('generations.estimate', {
            characters: characters.value,
            size: form.size,
            quality: form.quality,
            references: referenceUrls.value.length,
        });
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: pending.signal });

        if (!response.ok) {
            throw new Error();
        }

        estimate.value = await response.json();
        estimateError.value = null;
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            estimate.value = null;
            estimateError.value = 'Price unavailable right now.';
        }
    }
}

watchDebounced(() => [characters.value, form.size, form.quality, referenceUrls.value.length], refreshEstimate, {
    debounce: 250,
    immediate: true,
});

function submit() {
    form.transform((data) => ({ ...data, reference_images: referenceUrls.value })).post(route('generations.store'));
}

const optionClass = (active: boolean) =>
    cn(
        'rounded-md border px-3 py-2 text-sm capitalize transition-colors',
        active ? 'border-primary bg-primary text-primary-foreground' : 'border-input hover:bg-accent',
    );
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <div class="flex items-baseline justify-between">
                    <Label for="prompt">Prompt</Label>
                    <span class="text-xs text-muted-foreground" :class="{ 'text-red-600': characters > maxPromptCharacters }">
                        {{ characters }} / {{ maxPromptCharacters }}
                    </span>
                </div>
                <textarea
                    id="prompt"
                    v-model="form.prompt"
                    rows="4"
                    required
                    placeholder="A lighthouse on a cliff at sunset, oil painting"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-base placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:text-sm"
                />
                <InputError :message="form.errors.prompt" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Size</legend>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="size in sizes"
                        :key="size.value"
                        type="button"
                        :class="optionClass(form.size === size.value)"
                        :aria-pressed="form.size === size.value"
                        @click="form.size = size.value"
                    >
                        {{ size.label }} <span class="opacity-70">{{ size.value.replace('x', '×') }}</span>
                    </button>
                </div>
                <InputError :message="form.errors.size" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="mb-2 text-sm font-medium">Quality</legend>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="quality in qualities"
                        :key="quality"
                        type="button"
                        :class="optionClass(form.quality === quality)"
                        :aria-pressed="form.quality === quality"
                        @click="form.quality = quality"
                    >
                        {{ quality }}
                    </button>
                </div>
                <InputError :message="form.errors.quality" />
            </fieldset>

            <div class="grid gap-2">
                <Label
                    >Reference images <span class="font-normal text-muted-foreground">(optional, up to {{ maxReferenceImages }})</span></Label
                >
                <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                    <Input v-model="form.reference_images[index]" type="url" placeholder="https://example.com/image.png" />
                    <Button type="button" variant="ghost" size="icon" aria-label="Remove reference image" @click="removeReference(index)">
                        <X class="size-4" />
                    </Button>
                </div>
                <Button v-if="form.reference_images.length < maxReferenceImages" type="button" variant="outline" class="w-fit" @click="addReference">
                    <Plus class="size-4" /> Add reference image
                </Button>
                <InputError v-for="message in referenceErrors" :key="message" :message="message" />
            </div>

            <div class="rounded-lg border bg-muted/40 p-4">
                <p class="text-sm text-muted-foreground">Estimated cost</p>
                <p class="text-2xl font-semibold tabular-nums" aria-live="polite">
                    <template v-if="estimate">{{ estimate.min }} – {{ estimate.max }}</template>
                    <template v-else-if="estimateError">{{ estimateError }}</template>
                    <template v-else>…</template>
                </p>
                <p class="mt-1 text-xs text-muted-foreground">
                    You're charged the actual cost when the image is ready, always within this range. Failed generations are free.
                </p>
            </div>

            <Button type="submit" size="lg" :disabled="form.processing || characters === 0 || characters > maxPromptCharacters">
                {{ form.processing ? 'Submitting…' : 'Generate' }}
            </Button>
        </form>
    </AppLayout>
</template>
