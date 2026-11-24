import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Badge, Button, Card, ConfirmButton, ErrorState, Field, Input, Loading } from '@/components/ui'
import type { Translations } from '@/types'

interface AdminUnit {
  id: number
  code: string
  name: string
  name_translations: Translations
  sort_order: number
  is_active: boolean
}

/**
 * وحدات القياس.
 *
 * الرمز لاتينيّ ثابت يُحفظ مع المنتج، والاسم يُترجم هنا. اختيار الوحدة
 * يكون عند إضافة المنتج، وفي طلب السعر تُعرض ولا تُعدَّل.
 */
export function AdminUnits() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [editing, setEditing] = useState<AdminUnit | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['admin-units'],
    queryFn: async () => (await api.get<{ data: AdminUnit[] }>('/admin/units')).data.data,
  })

  function refreshLists() {
    void queryClient.invalidateQueries({ queryKey: ['admin-units'] })
    void queryClient.invalidateQueries({ queryKey: ['units'] })
  }

  function closeForm() {
    setEditing(null)
    setError(null)
    refreshLists()
  }

  function openCreate() {
    setError(null)
    setEditing('new')
  }

  function openEdit(unit: AdminUnit) {
    setError(null)
    setEditing(unit)
  }

  const save = useMutation({
    mutationFn: async ({ id, payload }: { id: number | null; payload: Record<string, unknown> }) => {
      if (id === null) {
        await api.post('/admin/units', payload)
      } else {
        await api.put(`/admin/units/${id}`, payload)
      }
    },
    onSuccess: () => closeForm(),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const destroy = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/admin/units/${id}`)).data,
    onSuccess: refreshLists,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const busy = save.isPending
  const closeRef = useRef(closeForm)
  closeRef.current = closeForm

  useEffect(() => {
    if (editing === null) return

    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !busy) closeRef.current()
    }

    document.addEventListener('keydown', onKey)
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previous
    }
  }, [editing, busy])

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const units = data ?? []
  const current = editing === 'new' ? null : editing

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)

    save.mutate({
      id: editing === 'new' ? null : (editing?.id ?? null),
      payload: {
        name: { ar: form.get('name_ar'), en: form.get('name_en') || form.get('name_ar') },
        ...(editing === 'new' ? { code: String(form.get('code') ?? '').trim().toLowerCase() } : {}),
        sort_order: Number(form.get('sort_order') || 0),
        is_active: form.get('is_active') === 'on',
      },
    })
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <Button size="sm" onClick={openCreate}>
          <Plus size={15} />
          {t.admin.newUnit}
        </Button>
        <span className="muted text-sm tabular">{units.length}</span>
      </div>

      {error && editing === null && <div className="card p-4 text-sm text-danger">{error}</div>}

      {editing !== null && (
        <div
          className="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !busy) closeForm()
          }}
        >
          <form
            key={current?.id ?? 'new'}
            role="dialog"
            aria-modal="true"
            aria-labelledby="unit-form-title"
            onSubmit={submit}
            onMouseDown={(event) => event.stopPropagation()}
            className="card my-auto grid w-full max-w-lg gap-4 p-5 sm:grid-cols-2"
          >
            <div className="flex items-center justify-between gap-3 sm:col-span-2">
              <h2 id="unit-form-title" className="text-base font-semibold">
                {current ? t.common.edit : t.admin.newUnit}
              </h2>
              <button
                type="button"
                onClick={closeForm}
                disabled={busy}
                aria-label={t.common.cancel}
                className="grid h-9 w-9 place-items-center rounded-lg text-[var(--muted)] hover:bg-[var(--surface)] hover:text-[var(--text)]"
              >
                <X size={18} />
              </button>
            </div>

            <Field label={t.common.nameAr}>
              <Input name="name_ar" required defaultValue={current?.name_translations?.ar ?? ''} />
            </Field>
            <Field label={t.common.nameEn}>
              <Input name="name_en" dir="ltr" defaultValue={current?.name_translations?.en ?? ''} />
            </Field>

            <Field label={t.admin.unitCode} hint={t.admin.unitCodeHint}>
              {current ? (
                <Input readOnly disabled dir="ltr" className="cursor-not-allowed opacity-70" value={current.code} />
              ) : (
                <Input name="code" required dir="ltr" pattern="[A-Za-z][A-Za-z0-9_-]*" maxLength={20} />
              )}
            </Field>

            <Field label={t.admin.sortOrder}>
              <Input
                name="sort_order"
                type="number"
                min={0}
                className="tabular"
                defaultValue={current?.sort_order ?? units.length}
              />
            </Field>

            <label className="flex items-center gap-2 text-sm sm:col-span-2">
              <input type="checkbox" name="is_active" defaultChecked={current?.is_active ?? true} />
              {t.admin.activeCategory}
            </label>

            <div className="flex gap-3 sm:col-span-2">
              <Button type="submit" disabled={busy}>
                {busy ? t.common.loading : t.common.save}
              </Button>
              <Button type="button" variant="ghost" onClick={closeForm} disabled={busy}>
                {t.common.cancel}
              </Button>
            </div>

            {error && <p className="text-sm text-danger sm:col-span-2">{error}</p>}
          </form>
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {units.map((unit) => (
          <Card key={unit.id}>
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <div className="font-semibold">
                  <span dir="auto">{unit.name}</span>
                </div>
                <div className="muted mt-0.5 text-xs tabular" dir="ltr">
                  {unit.code}
                </div>
              </div>
              {!unit.is_active && <Badge tone="neutral">{t.admin.disabled}</Badge>}
            </div>

            <div className="mt-4 flex flex-wrap gap-2">
              <Button variant="ghost" size="sm" onClick={() => openEdit(unit)}>
                <Pencil size={15} />
                {t.common.edit}
              </Button>
              <ConfirmButton
                label={t.common.delete}
                confirmLabel={t.admin.confirmDelete}
                onConfirm={() => destroy.mutate(unit.id)}
              >
                <Trash2 size={15} className="text-danger" />
              </ConfirmButton>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}
