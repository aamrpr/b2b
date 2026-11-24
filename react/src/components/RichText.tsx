import { forwardRef, useImperativeHandle, useState } from 'react'
import type { ReactNode } from 'react'
import { useEditor, EditorContent } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import Placeholder from '@tiptap/extension-placeholder'
import TextAlign from '@tiptap/extension-text-align'
import {
  AlignCenter,
  AlignLeft,
  AlignRight,
  Bold,
  Heading2,
  Italic,
  Link2,
  List,
  ListOrdered,
  Redo2,
  Strikethrough,
  Underline,
  Undo2,
} from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { Button, Input } from '@/components/ui'

export interface RichTextHandle {
  insert: (text: string) => void
}

/**
 * محرّر نص الرسالة.
 *
 * TipTap يُخرج فقرات وقوائم وروابط يفهمها بريد العميل. الاتجاه يتبع
 * اللغة التي تُحرَّر الآن، لا لغة واجهة السوبر أدمن.
 */
export const RichText = forwardRef<RichTextHandle, {
  value: string
  dir: 'rtl' | 'ltr'
  placeholder: string
  onChange: (html: string) => void
}>(function RichText({ value, dir, placeholder, onChange }, ref) {
  const { t } = useI18n()
  const tools = t.admin.emailTools
  const [link, setLink] = useState<string | null>(null)

  const editor = useEditor({
    immediatelyRender: true,
    shouldRerenderOnTransaction: true,
    extensions: [
      StarterKit.configure({
        code: false,
        codeBlock: false,
        horizontalRule: false,
        heading: { levels: [2, 3] },
        link: { openOnClick: false, autolink: true },
      }),
      TextAlign.configure({ types: ['heading', 'paragraph'] }),
      Placeholder.configure({ placeholder }),
    ],
    content: value,
    editorProps: {
      attributes: { dir, class: 'tiptap' },
    },
    onUpdate: ({ editor: current }) => onChange(current.getHTML()),
  })

  useImperativeHandle(ref, () => ({
    insert(text: string) {
      editor.chain().focus().insertContent(text).run()
    },
  }), [editor])

  const start = dir === 'rtl' ? 'right' : 'left'
  const end = dir === 'rtl' ? 'left' : 'right'
  const StartIcon = dir === 'rtl' ? AlignRight : AlignLeft
  const EndIcon = dir === 'rtl' ? AlignLeft : AlignRight

  function applyLink() {
    const href = (link ?? '').trim()
    if (href === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
    } else {
      editor.chain().focus().extendMarkRange('link').setLink({ href }).run()
    }
    setLink(null)
  }

  return (
    <div className="mail-editor">
      <div className="flex flex-wrap items-center gap-0.5 border-b border-[var(--border)] p-1.5">
        <Tool label={tools.bold} active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}>
          <Bold size={15} />
        </Tool>
        <Tool label={tools.italic} active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}>
          <Italic size={15} />
        </Tool>
        <Tool label={tools.underline} active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}>
          <Underline size={15} />
        </Tool>
        <Tool label={tools.strike} active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}>
          <Strikethrough size={15} />
        </Tool>
        <span className="mx-1 h-5 w-px bg-[var(--border)]" />
        <Tool label={tools.heading} active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}>
          <Heading2 size={15} />
        </Tool>
        <Tool label={tools.list} active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>
          <List size={15} />
        </Tool>
        <Tool label={tools.numbered} active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>
          <ListOrdered size={15} />
        </Tool>
        <span className="mx-1 h-5 w-px bg-[var(--border)]" />
        <Tool label={tools.alignStart} active={editor.isActive({ textAlign: start })} onClick={() => editor.chain().focus().setTextAlign(start).run()}>
          <StartIcon size={15} />
        </Tool>
        <Tool label={tools.alignCenter} active={editor.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()}>
          <AlignCenter size={15} />
        </Tool>
        <Tool label={tools.alignEnd} active={editor.isActive({ textAlign: end })} onClick={() => editor.chain().focus().setTextAlign(end).run()}>
          <EndIcon size={15} />
        </Tool>
        <span className="mx-1 h-5 w-px bg-[var(--border)]" />
        <Tool
          label={t.admin.emailLink}
          active={editor.isActive('link') || link !== null}
          onClick={() => setLink(editor.getAttributes('link').href ?? 'https://')}
        >
          <Link2 size={15} />
        </Tool>
        <span className="ms-auto flex">
          <Tool label={tools.undo} onClick={() => editor.chain().focus().undo().run()}>
            <Undo2 size={15} />
          </Tool>
          <Tool label={tools.redo} onClick={() => editor.chain().focus().redo().run()}>
            <Redo2 size={15} />
          </Tool>
        </span>
      </div>

      {link !== null && (
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--border)] p-2">
          <Input
            dir="ltr"
            value={link}
            onChange={(event) => setLink(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === 'Enter') {
                event.preventDefault()
                applyLink()
              }
            }}
            placeholder="https://"
            aria-label={t.admin.emailLink}
            className="min-w-0 flex-1"
          />
          <Button type="button" size="sm" onClick={applyLink}>
            {t.admin.emailApplyLink}
          </Button>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => {
              editor.chain().focus().extendMarkRange('link').unsetLink().run()
              setLink(null)
            }}
          >
            {t.admin.emailRemoveLink}
          </Button>
        </div>
      )}

      <EditorContent editor={editor} />
    </div>
  )
})

function Tool({
  label,
  active = false,
  onClick,
  children,
}: {
  label: string
  active?: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      title={label}
      aria-label={label}
      aria-pressed={active}
      onMouseDown={(event) => event.preventDefault()}
      onClick={onClick}
      className={clsx(
        'grid h-8 w-8 place-items-center rounded-md text-[var(--muted)] hover:bg-[var(--surface-raised)] hover:text-[var(--text)]',
        active && 'bg-[var(--accent)]/15 text-[var(--accent)]',
      )}
    >
      {children}
    </button>
  )
}
