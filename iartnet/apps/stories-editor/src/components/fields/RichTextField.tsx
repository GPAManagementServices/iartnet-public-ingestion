import './RichTextField.css'

import Image from '@tiptap/extension-image'
import Link from '@tiptap/extension-link'
import type { Extensions } from '@tiptap/core'
import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { useCallback, useEffect, useId, useMemo, useState } from 'react'
import { Button, Form } from 'react-bootstrap'
import { editorHtmlToStoredValue, storedValueToEditorHtml } from '../../story/richText'
import { parseRichTextImageUrl } from '../../story/richTextSanitize'
import { FORM_GROUP_GAP, FORM_LABEL } from '../formStyles'

type Props = {
  label: string
  value: string
  onChange: (v: string) => void
  disabled?: boolean
  /** Caption / annotazioni IIIF: inserimento immagini via URL. */
  allowImages?: boolean
}

function fieldSlug(label: string, reactId: string): string {
  const base = label.replace(/\W+/g, '-').toLowerCase() || 'field'
  return `${base}-${reactId}`
}

export function RichTextField({ label, value, onChange, disabled, allowImages = false }: Props) {
  const reactId = useId()
  const labelId = `${fieldSlug(label, reactId)}-label`
  const toolbarId = `${fieldSlug(label, reactId)}-toolbar`
  const [showSource, setShowSource] = useState(false)

  const sanitizeOptions = useMemo(
    () => (allowImages ? ({ allowImages: true } as const) : undefined),
    [allowImages],
  )

  const emitChange = useCallback(
    (html: string) => {
      onChange(editorHtmlToStoredValue(html, sanitizeOptions))
    },
    [onChange, sanitizeOptions],
  )

  const extensions = useMemo((): Extensions => {
    const list: Extensions = [
      StarterKit.configure({
        blockquote: false,
        bulletList: false,
        code: false,
        codeBlock: false,
        heading: false,
        horizontalRule: false,
        link: false,
        orderedList: false,
        strike: false,
      }),
      Link.configure({
        openOnClick: false,
        HTMLAttributes: {
          target: '_blank',
          rel: 'noopener noreferrer',
        },
      }),
    ]
    if (allowImages) {
      list.push(
        Image.configure({
          inline: true,
          HTMLAttributes: { alt: '' },
        }),
      )
    }
    return list
  }, [allowImages])

  const editor = useEditor({
    immediatelyRender: false,
    extensions,
    content: storedValueToEditorHtml(value),
    editable: !disabled,
    onUpdate: ({ editor: ed }) => {
      emitChange(ed.getHTML())
    },
  })

  useEffect(() => {
    if (!editor) return
    editor.setEditable(!disabled)
  }, [editor, disabled])

  useEffect(() => {
    if (!editor || showSource) return
    const current = editorHtmlToStoredValue(editor.getHTML(), sanitizeOptions)
    if (current !== value) {
      editor.commands.setContent(storedValueToEditorHtml(value), { emitUpdate: false })
    }
  }, [editor, value, showSource, sanitizeOptions])

  const toggleSource = useCallback(() => {
    setShowSource((prev) => !prev)
  }, [])

  const onSourceChange = useCallback(
    (raw: string) => {
      onChange(editorHtmlToStoredValue(raw, sanitizeOptions))
    },
    [onChange, sanitizeOptions],
  )

  const toggleBold = useCallback(() => {
    editor?.chain().focus().toggleBold().run()
  }, [editor])

  const toggleItalic = useCallback(() => {
    editor?.chain().focus().toggleItalic().run()
  }, [editor])

  const setLink = useCallback(() => {
    if (!editor) return
    const previous = editor.getAttributes('link').href as string | undefined
    const url = window.prompt('URL del link', previous ?? 'https://')
    if (url === null) return
    const trimmed = url.trim()
    if (trimmed === '') {
      editor.chain().focus().extendMarkRange('link').unsetLink().run()
      return
    }
    editor.chain().focus().extendMarkRange('link').setLink({ href: trimmed }).run()
  }, [editor])

  const unsetLink = useCallback(() => {
    editor?.chain().focus().extendMarkRange('link').unsetLink().run()
  }, [editor])

  const insertImage = useCallback(() => {
    if (!editor || !allowImages) return
    const url = window.prompt('URL immagine', 'https://')
    if (url === null) return
    const src = parseRichTextImageUrl(url)
    if (!src) {
      window.alert('URL non valido: usare un indirizzo http:// o https://')
      return
    }
    editor.chain().focus().setImage({ src, alt: '' }).run()
  }, [editor, allowImages])

  return (
    <Form.Group className={FORM_GROUP_GAP} role="group" aria-labelledby={labelId}>
      <Form.Label id={labelId} className={FORM_LABEL}>
        {label}
      </Form.Label>
      <div className="rich-text-field border rounded bg-white">
        <div
          id={toolbarId}
          className="rich-text-field__toolbar d-flex flex-wrap gap-1 border-bottom px-2 py-1"
          role="toolbar"
          aria-label={`Formattazione ${label}`}
        >
          <Button
            type="button"
            variant={editor?.isActive('bold') ? 'secondary' : 'outline-secondary'}
            size="sm"
            className="rich-text-field__btn"
            disabled={disabled || !editor || showSource}
            aria-pressed={editor?.isActive('bold') ?? false}
            aria-label="Grassetto"
            title="Grassetto"
            onClick={toggleBold}
          >
            <i className="bi bi-type-bold" aria-hidden />
          </Button>
          <Button
            type="button"
            variant={editor?.isActive('italic') ? 'secondary' : 'outline-secondary'}
            size="sm"
            className="rich-text-field__btn"
            disabled={disabled || !editor || showSource}
            aria-pressed={editor?.isActive('italic') ?? false}
            aria-label="Corsivo"
            title="Corsivo"
            onClick={toggleItalic}
          >
            <i className="bi bi-type-italic" aria-hidden />
          </Button>
          <Button
            type="button"
            variant={editor?.isActive('link') ? 'secondary' : 'outline-secondary'}
            size="sm"
            className="rich-text-field__btn"
            disabled={disabled || !editor || showSource}
            aria-pressed={editor?.isActive('link') ?? false}
            aria-label="Link"
            title="Inserisci link"
            onClick={setLink}
          >
            <i className="bi bi-link-45deg" aria-hidden />
          </Button>
          <Button
            type="button"
            variant="outline-secondary"
            size="sm"
            className="rich-text-field__btn"
            disabled={disabled || !editor || showSource || !editor.isActive('link')}
            aria-label="Rimuovi link"
            title="Rimuovi link"
            onClick={unsetLink}
          >
            <i className="bi bi-link-45deg-slash" aria-hidden />
          </Button>
          {allowImages ? (
            <Button
              type="button"
              variant="outline-secondary"
              size="sm"
              className="rich-text-field__btn"
              disabled={disabled || !editor || showSource}
              aria-label="Inserisci immagine"
              title="Inserisci immagine"
              onClick={insertImage}
            >
              <i className="bi bi-image" aria-hidden />
            </Button>
          ) : null}
          <Button
            type="button"
            variant={showSource ? 'secondary' : 'outline-secondary'}
            size="sm"
            className="rich-text-field__btn ms-auto"
            disabled={disabled}
            aria-pressed={showSource}
            aria-label="Sorgente HTML"
            title="Sorgente HTML"
            onClick={toggleSource}
          >
            <i className="bi bi-code-slash" aria-hidden />
          </Button>
        </div>
        {showSource ? (
          <Form.Control
            as="textarea"
            className="rich-text-field__source font-monospace border-0 rounded-0 shadow-none"
            rows={6}
            value={value}
            disabled={disabled}
            aria-label={`Sorgente HTML ${label}`}
            onChange={(e) => onSourceChange(e.target.value)}
          />
        ) : (
          <div className="rich-text-field__editor px-2 py-1">
            <EditorContent editor={editor} />
          </div>
        )}
      </div>
    </Form.Group>
  )
}
