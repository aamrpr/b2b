import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Button, Card, ErrorState, Field, Input, Loading } from '@/components/ui'
import { RichText, type RichTextHandle } from '@/components/RichText'
import { ar } from '@/i18n/ar'
import { en } from '@/i18n/en'
import type { Translations } from '@/types'
import clsx from 'clsx'

interface EmailTemplate {
  key: string
  enabled: boolean
  subject_translations: Translations
  body_translations: Translations
  placeholders: string[]
}

type Lang = 'ar' | 'en'

/**
 * رسائل العميل.
 *
 * السوبر أدمن يفعّل كل رسالة ويحرّر موضوعها ونصها بالعربية والإنجليزية.
 * الشعار والإطار والزر ثابتان، والعناصر مثل {{name}} تُستبدل عند الإرسال.
 */
export function AdminEmails() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<EmailTemplate | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['admin-email-templates'],
    queryFn: async () => (await api.get<{ data: EmailTemplate[] }>('/admin/email-templates')).data.data,
  })

  const save = useMutation({
    mutationFn: async (payload: {
      key: string
      enabled: boolean
      subject: Translations
      body: Translations
    }) => {
      await api.put(`/admin/email-templates/${payload.key}`, {
        enabled: payload.enabled,
        subject: payload.subject,
        body: payload.body,
      })
    },
    onSuccess: async () => {
      setEditing(null)
      setError(null)
      await queryClient.invalidateQueries({ queryKey: ['admin-email-templates'] })
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const templates = data ?? []

  return (
    <div className="space-y-5">
      <div>
        <h2 className="text-base font-semibold">{t.admin.emails}</h2>
        <p className="muted mt-1 max-w-2xl text-sm">{t.admin.emailsSubtitle}</p>
      </div>

      {error && editing === null && <div className="card p-4 text-sm text-danger">{error}</div>}

      <div className="grid gap-3 lg:grid-cols-2">
        {templates.map((template) => (
          <Card key={template.key}>
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="font-semibold">{label(t.admin.emailEvents, template.key)}</div>
                <p className="muted mt-1 text-sm">{label(t.admin.emailHints, template.key)}</p>
              </div>
              <Switch
                checked={template.enabled}
                disabled={save.isPending}
                onLabel={t.admin.activeCategory}
                offLabel={t.admin.disabled}
                onChange={() => save.mutate({
                  key: template.key,
                  enabled: !template.enabled,
                  subject: template.subject_translations,
                  body: template.body_translations,
                })}
              />
            </div>
            <div className="mt-4">
              <Button
                variant="ghost"
                size="sm"
                onClick={() => {
                  setError(null)
                  setEditing(template)
                }}
              >
                <Pencil size={15} />
                {t.admin.editEmail}
              </Button>
            </div>
          </Card>
        ))}
      </div>

      {editing && (
        <EmailEditor
          template={editing}
          busy={save.isPending}
          error={error}
          onClose={() => {
            if (!save.isPending) {
              setEditing(null)
              setError(null)
            }
          }}
          onSave={(payload) => save.mutate(payload)}
        />
      )}
    </div>
  )
}

function EmailEditor({
  template,
  busy,
  error,
  onClose,
  onSave,
}: {
  template: EmailTemplate
  busy: boolean
  error: string | null
  onClose: () => void
  onSave: (payload: { key: string; enabled: boolean; subject: Translations; body: Translations }) => void
}) {
  const { t } = useI18n()
  const editorRef = useRef<RichTextHandle>(null)
  const [lang, setLang] = useState<Lang>('ar')
  const [enabled, setEnabled] = useState(template.enabled)
  const [subject, setSubject] = useState<Translations>({
    ar: template.subject_translations.ar ?? '',
    en: template.subject_translations.en ?? '',
  })
  const [body, setBody] = useState<Translations>({
    ar: template.body_translations.ar ?? '',
    en: template.body_translations.en ?? '',
  })

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busy) onClose()
    }
    document.addEventListener('keydown', onKey)
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previous
    }
  }, [busy, onClose])

  return (
    <div
      className="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget && !busy) onClose()
      }}
    >
      <form
        role="dialog"
        aria-modal="true"
        aria-labelledby="email-form-title"
        onSubmit={(event) => {
          event.preventDefault()
          onSave({ key: template.key, enabled, subject, body })
        }}
        onMouseDown={(event) => event.stopPropagation()}
        className="card my-auto grid w-full max-w-3xl gap-4 p-5"
      >
        <div className="flex items-center justify-between gap-3">
          <h2 id="email-form-title" className="text-base font-semibold">
            {label(t.admin.emailEvents, template.key)}
          </h2>
          <button
            type="button"
            onClick={onClose}
            disabled={busy}
            aria-label={t.common.cancel}
            className="grid h-9 w-9 place-items-center rounded-lg text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--text)]"
          >
            <X size={18} />
          </button>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex rounded-lg border border-[var(--border)] p-0.5" role="tablist" aria-label={t.admin.emailLanguage}>
            {(['ar', 'en'] as const).map((code) => (
              <button
                key={code}
                type="button"
                role="tab"
                aria-selected={lang === code}
                onClick={() => setLang(code)}
                className={clsx(
                  'rounded-md px-3 py-1.5 text-sm',
                  lang === code ? 'bg-[var(--accent)] text-white' : 'text-[var(--muted)]',
                )}
              >
                {code === 'ar' ? 'العربية' : 'English'}
              </button>
            ))}
          </div>
          <Switch
            checked={enabled}
            disabled={busy}
            onLabel={t.admin.activeCategory}
            offLabel={t.admin.disabled}
            onChange={() => setEnabled((current) => !current)}
          />
        </div>

        <Field label={t.admin.emailSubject}>
          <Input
            required
            dir={lang === 'ar' ? 'rtl' : 'ltr'}
            value={subject[lang] ?? ''}
            onChange={(event) => setSubject((current) => ({ ...current, [lang]: event.target.value }))}
          />
        </Field>

        <div>
          <div className="mb-1.5 text-sm font-medium">{t.admin.emailBody}</div>
          <div className="overflow-hidden rounded-2xl border border-[#dde3ec] bg-white text-[#142d4e]" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
            <div className="bg-[#142d4e] px-6 py-5">
              <img src="/brand/logo-dark.png" alt={ar.app.name} className="h-10 w-auto" />
              <p className="mt-2 text-sm text-[#96dcd9]">
                {lang === 'ar' ? ar.app.tagline : en.app.tagline}
              </p>
            </div>
            <div className="h-1 bg-[#16a6a1]" />
            <div className="p-4">
              <RichText
                key={lang}
                ref={editorRef}
                dir={lang === 'ar' ? 'rtl' : 'ltr'}
                placeholder={t.admin.emailBody}
                value={body[lang] ?? ''}
                onChange={(html) => setBody((current) => ({ ...current, [lang]: html }))}
              />
              <span className="mt-4 inline-flex rounded-[10px] bg-[#087f7b] px-4 py-3 text-sm font-semibold text-white">
                {lang === 'ar' ? 'فتح في المستورد' : 'Open in Al Mustawred'}
              </span>
            </div>
            <p className="border-t border-[#dde3ec] px-6 py-3 text-xs text-[#5b708d]">
              {lang === 'ar' ? 'هذه رسالة آلية من المستورد.' : 'This is an automatic message from Al Mustawred.'}
            </p>
          </div>
        </div>

        <div>
          <div className="mb-1.5 text-xs text-[var(--muted)]">{t.admin.emailPlaceholders}</div>
          <div className="flex flex-wrap gap-2">
            {template.placeholders.map((token) => (
              <button
                key={token}
                type="button"
                onClick={() => editorRef.current?.insert(`{{${token}}}`)}
                className="rounded-full border border-[var(--border)] px-2.5 py-1 text-xs hover:border-[var(--accent)] hover:text-[var(--accent)]"
              >
                {label(t.admin.placeholders, token)}
                <span className="ms-1 tabular text-[var(--muted)]" dir="ltr">{`{{${token}}}`}</span>
              </button>
            ))}
          </div>
        </div>

        {error && <p className="text-sm text-danger">{error}</p>}

        <div className="flex gap-3">
          <Button type="submit" disabled={busy}>
            {busy ? t.common.loading : t.common.save}
          </Button>
          <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>
            {t.common.cancel}
          </Button>
        </div>
      </form>
    </div>
  )
}

function Switch({
  checked,
  disabled,
  onLabel,
  offLabel,
  onChange,
}: {
  checked: boolean
  disabled?: boolean
  onLabel: string
  offLabel: string
  onChange: () => void
}) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      disabled={disabled}
      onClick={onChange}
      className="inline-flex items-center gap-2 text-sm disabled:opacity-50"
    >
      <span
        className={clsx(
          'relative h-6 w-11 rounded-full transition',
          checked ? 'bg-[var(--accent)]' : 'bg-[var(--border)]',
        )}
      >
        <span
          className={clsx(
            'absolute top-0.5 h-5 w-5 rounded-full bg-white transition',
            checked ? 'start-5' : 'start-0.5',
          )}
        />
      </span>
      <span className="text-[var(--muted)]">{checked ? onLabel : offLabel}</span>
    </button>
  )
}

function label(bag: Record<string, string>, key: string): string {
  return Object.prototype.hasOwnProperty.call(bag, key) ? bag[key] : key
}
