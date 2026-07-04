<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue'
import BaseButton from '@/components/atoms/buttons/BaseButton.vue'
import { useTutorials } from '../composables/useTutorials'
import type { Tutorial, TutorialSource } from '../types/tutorial.types'
import AsyncContent from '@/components/shared/AsyncContent.vue'

const { data: tutorials, isLoading, error } = useTutorials()

const currentIndex = shallowRef(0)

watch(tutorials, (list) => {
  if (list && list.length > 0) {
    currentIndex.value = 0
  }
}, { immediate: true })

const currentTutorial = computed<Tutorial | null>(() =>
  tutorials.value?.[currentIndex.value] ?? null,
)

const isEmpty = computed(() =>
  !isLoading.value && (!tutorials.value || tutorials.value.length === 0),
)

const isFirst = computed(() => currentIndex.value === 0)
const isLast = computed(() =>
  !tutorials.value || currentIndex.value === tutorials.value.length - 1,
)

function buildEmbedUrl(source: TutorialSource, code: string): string {
  if (source === 'youtube') {
    return `https://www.youtube.com/embed/${code}?rel=0&modestbranding=1`
  }
  return `https://player.vimeo.com/video/${code}?dnt=1`
}

const embedUrl = computed<string>(() => {
  if (!currentTutorial.value) return ''
  return buildEmbedUrl(currentTutorial.value.source, currentTutorial.value.code)
})

function selectTutorial(index: number): void {
  currentIndex.value = index
}

function goPrev(): void {
  if (!isFirst.value) currentIndex.value--
}

function goNext(): void {
  if (!isLast.value) currentIndex.value++
}
</script>

<template>
  <div class="p-6 max-w-screen-xl mx-auto">
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-xl font-semibold text-gray-900 leading-none">Tutoriales</h1>
        <p v-if="tutorials && tutorials.length > 0" class="text-sm text-gray-500 mt-1">
          {{ tutorials.length }} {{ tutorials.length === 1 ? 'video' : 'videos' }}
        </p>
      </div>
    </div>

    <AsyncContent
      :loading="isLoading"
      :error="error"
      :is-empty="isEmpty"
      error-message="No se pudieron cargar los tutoriales. Verificá tu conexión e intentá nuevamente."
      empty-description="No hay tutoriales disponibles."
    >
      <div class="flex gap-6 items-start">
        <aside class="w-64 flex-shrink-0">
          <ul class="space-y-1">
            <li
              v-for="(tutorial, index) in tutorials"
              :key="tutorial.guid"
            >
              <button
                type="button"
                class="w-full text-left px-3 py-2.5 rounded-lg flex items-start gap-2.5 transition-colors duration-150"
                :class="index === currentIndex
                  ? 'bg-blue-600 text-white'
                  : 'text-gray-700 hover:bg-gray-100'"
                @click="selectTutorial(index)"
              >
                <span
                  class="flex-shrink-0 text-xs font-semibold mt-0.5 w-5 h-5 rounded-full flex items-center justify-center"
                  :class="index === currentIndex ? 'bg-white/20 text-white' : 'bg-gray-200 text-gray-500'"
                >
                  {{ tutorial.order }}
                </span>
                <span class="text-sm font-medium leading-snug">{{ tutorial.title }}</span>
              </button>
            </li>
          </ul>
        </aside>

        <div class="flex-1 min-w-0">
          <div v-if="currentTutorial">
            <div class="w-full rounded-xl overflow-hidden bg-black shadow-md" style="aspect-ratio: 16/9;">
              <iframe
                :key="embedUrl"
                :src="embedUrl"
                :title="currentTutorial.title"
                class="w-full h-full border-0"
                allow="autoplay; fullscreen; picture-in-picture"
                allowfullscreen
              />
            </div>

            <div class="mt-4">
              <h2 class="text-lg font-semibold text-gray-900">{{ currentTutorial.title }}</h2>
              <p
                v-if="currentTutorial.description"
                class="mt-1.5 text-sm text-gray-600 leading-relaxed"
              >
                {{ currentTutorial.description }}
              </p>
            </div>

            <div class="mt-6 flex items-center justify-between">
              <BaseButton
                variant="secondary"
                :disabled="isFirst"
                @click="goPrev"
              >
                ← Anterior
              </BaseButton>

              <span class="text-xs text-gray-400">
                {{ currentIndex + 1 }} / {{ tutorials?.length }}
              </span>

              <BaseButton
                variant="primary"
                :disabled="isLast"
                @click="goNext"
              >
                Siguiente →
              </BaseButton>
            </div>
          </div>
        </div>
      </div>
    </AsyncContent>
  </div>
</template>
