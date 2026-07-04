<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import Placeholder from '@tiptap/extension-placeholder'
import {
  BoldOutlined,
  ItalicOutlined,
  UnderlineOutlined,
  UnorderedListOutlined,
  OrderedListOutlined,
} from '@ant-design/icons-vue'

const props = withDefaults(defineProps<{
  modelValue?: string
  placeholder?: string
  hasError?: boolean
  disabled?: boolean
}>(), {
  modelValue: '',
  placeholder: '',
  hasError: false,
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: string]
  blur: []
}>()

const isFocused = ref(false)

const editor = useEditor({
  content: props.modelValue,
  editable: !props.disabled,
  extensions: [
    StarterKit,
    Underline,
    Placeholder.configure({ placeholder: props.placeholder }),
  ],
  editorProps: {
    attributes: {
      class: 'rte-editor',
    },
  },
  onUpdate: ({ editor }) => {
    emit('update:modelValue', editor.getHTML())
  },
  onFocus: () => { isFocused.value = true },
  onBlur: () => {
    isFocused.value = false
    emit('blur')
  },
})

watch(() => props.modelValue, (val) => {
  if (!editor.value) return
  const current = editor.value.getHTML()
  if (val !== current) {
    editor.value.commands.setContent(val || '', false)
  }
})

watch(() => props.disabled, (val) => {
  editor.value?.setEditable(!val)
})

onBeforeUnmount(() => editor.value?.destroy())
</script>

<template>
  <div :class="['rte', { 'rte--error': hasError, 'rte--focused': isFocused, 'rte--disabled': disabled }]">
    <div v-if="editor" class="rte-toolbar">
      <button type="button" :class="{ 'rte-btn--active': editor.isActive('bold') }" class="rte-btn" title="Negrita" @click="editor.chain().focus().toggleBold().run()">
        <BoldOutlined />
      </button>
      <button type="button" :class="{ 'rte-btn--active': editor.isActive('italic') }" class="rte-btn" title="Cursiva" @click="editor.chain().focus().toggleItalic().run()">
        <ItalicOutlined />
      </button>
      <button type="button" :class="{ 'rte-btn--active': editor.isActive('underline') }" class="rte-btn" title="Subrayado" @click="editor.chain().focus().toggleUnderline().run()">
        <UnderlineOutlined />
      </button>
      <span class="rte-separator" />
      <button type="button" :class="{ 'rte-btn--active': editor.isActive('bulletList') }" class="rte-btn" title="Lista" @click="editor.chain().focus().toggleBulletList().run()">
        <UnorderedListOutlined />
      </button>
      <button type="button" :class="{ 'rte-btn--active': editor.isActive('orderedList') }" class="rte-btn" title="Lista numerada" @click="editor.chain().focus().toggleOrderedList().run()">
        <OrderedListOutlined />
      </button>
    </div>
    <EditorContent :editor="editor" class="rte-body" />
  </div>
</template>

<style scoped>
.rte {
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  transition: border-color 0.2s, box-shadow 0.2s;
  background: #fff;
}

.rte--focused {
  border-color: #1677ff;
  box-shadow: 0 0 0 2px rgba(5, 145, 255, 0.1);
}

.rte--error {
  border-color: #ff4d4f;
}

.rte--error.rte--focused {
  box-shadow: 0 0 0 2px rgba(255, 77, 79, 0.1);
}

.rte--disabled {
  background: #f5f5f5;
  cursor: not-allowed;
}

.rte-toolbar {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 4px 8px;
  border-bottom: 1px solid #f0f0f0;
}

.rte-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 4px;
  color: #595959;
  cursor: pointer;
  font-size: 14px;
  transition: background 0.15s, color 0.15s;
}

.rte-btn:hover {
  background: #f0f0f0;
  color: #000;
}

.rte-btn--active {
  background: #e6f4ff;
  color: #1677ff;
}

.rte-separator {
  width: 1px;
  height: 18px;
  background: #e0e0e0;
  margin: 0 4px;
}

.rte-body {
  padding: 8px 11px;
  min-height: 120px;
}

.rte-body :deep(.rte-editor) {
  outline: none;
  min-height: 104px;
  font-size: 14px;
  line-height: 1.5715;
  color: rgba(0, 0, 0, 0.88);
}

.rte-body :deep(.rte-editor p) {
  margin: 0 0 4px;
}

.rte-body :deep(.rte-editor p:last-child) {
  margin-bottom: 0;
}

.rte-body :deep(.rte-editor ul),
.rte-body :deep(.rte-editor ol) {
  padding-left: 20px;
  margin: 4px 0;
}

.rte-body :deep(.rte-editor p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  color: rgba(0, 0, 0, 0.25);
  pointer-events: none;
  float: left;
  height: 0;
}
</style>
