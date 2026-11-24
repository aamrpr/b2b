import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ImagePlus, Pencil, Plus, Trash2, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Thumb } from '@/components/Thumb'
import { Badge, Button, Card, ConfirmButton, ErrorState, Field, Input, Loading } from '@/components/ui'
import type { AdminCategory } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'

/**
 * شجرة الأصناف.
 *
 * الأصناف ملك المنصة لا المستوردين: بها يُصنَّف الكتالوج، وإليها تُرسل
 * طلبات الأسعار، وعليها يُبنى تسجيل المستورد. فإضافتها وتعديلها — ومعهما
 * صورة البطاقة — من نافذة واحدة، لا من بطاقةٍ تتوسّط الصفحة.
 *
 * والمعطَّل يظهر في هذه الصفحة وحدها: القائمة العامة تُرجع الفعّال وحده،
 * ولو قرأت الإدارة منها لاختفى عنها ما عطّلته فلم تستطع إعادته.
 */
export function AdminCategories() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [editing, setEditing] = useState<AdminCategory | 'new' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [keptUrl, setKeptUrl] = useState<string | null>(null)
  const [dropImage, setDropImage] = useState(false)
  const [pending, setPending] = useState<{ file: File; url: string } | null>(null)
  const savedId = useRef<number | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['admin-categories'],
    queryFn: async () => (await api.get<{ data: AdminCategory[] }>('/admin/categories')).data.data,
  })

  function refreshLists() {
    void queryClient.invalidateQueries({ queryKey: ['admin-categories'] })
    void queryClient.invalidateQueries({ queryKey: ['categories'] })
  }

  function forgetPending() {
    setPending((current) => {
      if (current) URL.revokeObjectURL(current.url)
      return null
    })
  }

  function closeForm() {
    forgetPending()
    setKeptUrl(null)
    setDropImage(false)
    savedId.current = null
    setEditing(null)
    setError(null)
    refreshLists()
  }

  function openCreate() {
    forgetPending()
    setKeptUrl(null)
    setDropImage(false)
    savedId.current = null
    setError(null)
    setEditing('new')
  }

  function openEdit(category: AdminCategory) {
    forgetPending()
    setKeptUrl(category.image_url)
    setDropImage(false)
    savedId.current = category.id
    setError(null)
    setEditing(category)
  }

  const save = useMutation({
    mutationFn: async ({
      id,
      payload,
      file,
      removeImage,
    }: {
      id: number | null
      payload: Record<string, unknown>
      file: File | null
      removeImage: boolean
    }) => {
      let categoryId = id ?? savedId.current

      if (categoryId === null) {
        const created = (await api.post<{ id: number }>('/admin/categories', payload)).data
        categoryId = created.id
        savedId.current = categoryId
      } else {
        await api.put(`/admin/categories/${categoryId}`, payload)
      }

      if (file) {
        const form = new FormData()
        form.append('image', file)
        await api.post(`/admin/categories/${categoryId}/image`, form)
      } else if (removeImage) {
        await api.delete(`/admin/categories/${categoryId}/image`)
      }
    },
    onSuccess: () => closeForm(),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const destroy = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/admin/categories/${id}`)).data,
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

  const roots = data ?? []
  const flat = roots.flatMap((root) => [root, ...(root.children ?? [])])
  const current = editing === 'new' ? null : editing
  const preview = pending?.url ?? (dropImage ? null : keptUrl)

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const parent = String(form.get('parent_id') ?? '')

    save.mutate({
      id: editing === 'new' ? null : (editing?.id ?? null),
      payload: {
        name: { ar: form.get('name_ar'), en: form.get('name_en') || form.get('name_ar') },
        // الأب يُختار عند الإنشاء فقط، ولا يُعاد إرساله عند التعديل
        ...(editing === 'new' ? { parent_id: parent ? Number(parent) : null } : {}),
        icon: form.get('icon') || null,
        sort_order: Number(form.get('sort_order') || 0),
        is_active: form.get('is_active') === 'on',
        is_featured: form.get('is_featured') === 'on',
      },
      file: pending?.file ?? null,
      removeImage: dropImage && !pending,
    })
  }

  function pickImage(list: FileList | null) {
    const file = list?.[0]
    if (!file) return

    forgetPending()
    setDropImage(false)
    setPending({ file, url: URL.createObjectURL(file) })
    setError(null)
  }

  function clearImage() {
    forgetPending()
    setDropImage(true)
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <Button size="sm" onClick={openCreate}>
          <Plus size={15} />
          {t.admin.newCategory}
        </Button>
        <span className="muted text-sm tabular">{flat.length}</span>
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
            aria-labelledby="category-form-title"
            onSubmit={submit}
            onMouseDown={(event) => event.stopPropagation()}
            className="card my-auto grid w-full max-w-2xl gap-4 p-5 sm:grid-cols-2"
          >
            <div className="flex items-center justify-between gap-3 sm:col-span-2">
              <h2 id="category-form-title" className="text-base font-semibold">
                {current ? t.common.edit : t.admin.newCategory}
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

            <Field label={t.admin.parentCategory}>
              {current ? (
                <Input
                  readOnly
                  disabled
                  className="cursor-not-allowed opacity-70"
                  value={
                    current.parent_id
                      ? (roots.find((root) => root.id === current.parent_id)?.name ?? '—')
                      : t.admin.rootCategory
                  }
                />
              ) : (
                <SearchSelect
                  name="parent_id"
                  isClearable
                  placeholder={t.admin.rootCategory}
                  options={roots.map((root) => ({ value: String(root.id), label: root.name }))}
                />
              )}
            </Field>

            <Field label={t.admin.sortOrder}>
              <Input
                name="sort_order"
                type="number"
                min={0}
                className="tabular"
                defaultValue={current?.sort_order ?? 0}
              />
            </Field>

            <Field label={t.admin.iconName} hint="lucide">
              <Input name="icon" dir="ltr" defaultValue={current?.icon ?? ''} />
            </Field>

            <div className="flex items-end gap-5 text-sm">
              <label className="flex items-center gap-2">
                <input type="checkbox" name="is_active" defaultChecked={current?.is_active ?? true} />
                {t.admin.activeCategory}
              </label>
              <label className="flex items-center gap-2">
                <input type="checkbox" name="is_featured" defaultChecked={current?.is_featured ?? false} />
                {t.admin.featured}
              </label>
            </div>

            <div className="sm:col-span-2">
              <div className="text-sm font-medium">{t.products.categoryImage}</div>
              <p className="muted mt-0.5 text-xs">{t.admin.imageHint}</p>

              <div className="mt-3 flex flex-wrap items-center gap-3">
                <div className="h-28 w-40 overflow-hidden rounded-lg border border-[var(--border)]">
                  {preview ? (
                    <img src={preview} alt="" className="h-full w-full object-cover" />
                  ) : (
                    <div className="grid h-full place-items-center px-3 text-center text-xs muted">
                      {t.products.noImage}
                    </div>
                  )}
                </div>

                <div className="flex flex-col items-start gap-2">
                  <Button type="button" variant="outline" size="sm" onClick={() => fileInput.current?.click()}>
                    <ImagePlus size={15} />
                    {preview ? t.admin.changeImage : t.account.upload}
                  </Button>
                  {preview && (
                    <Button type="button" variant="ghost" size="sm" onClick={clearImage}>
                      {t.account.removeImage}
                    </Button>
                  )}
                </div>
              </div>

              <input
                ref={fileInput}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(event) => {
                  pickImage(event.target.files)
                  event.target.value = ''
                }}
              />
            </div>

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

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {roots.map((category) => (
          <Card key={category.id} className="overflow-hidden p-0">
            <Thumb src={category.image_thumb_url || category.image_url} alt={category.name} rounded="rounded-none" className="h-32 w-full" />

            <div className="p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="font-semibold">
                    <span dir="auto">{category.name}</span>
                  </div>
                  <div className="muted mt-0.5 text-xs tabular" dir="ltr">
                    {category.slug}
                  </div>
                </div>
                <div className="flex shrink-0 gap-1">
                  {category.is_featured && <Badge tone="brand">★</Badge>}
                  {!category.is_active && <Badge tone="neutral">{t.admin.disabled}</Badge>}
                </div>
              </div>

              <div className="mt-4 flex flex-wrap gap-2">
                <Button variant="ghost" size="sm" onClick={() => openEdit(category)}>
                  <Pencil size={15} />
                  {t.common.edit}
                </Button>

                <ConfirmButton
                  label={t.common.delete}
                  confirmLabel={t.admin.confirmDelete}
                  onConfirm={() => destroy.mutate(category.id)}
                >
                  <Trash2 size={15} className="text-danger" />
                </ConfirmButton>
              </div>

              {category.children?.length ? (
                <ul className="mt-4 space-y-1 border-t border-[var(--border)] pt-3 text-sm">
                  {category.children.map((child) => (
                    <li key={child.id} className="flex items-center justify-between gap-2">
                      <span className={child.is_active ? '' : 'muted line-through'}>
                        <span dir="auto">{child.name}</span>
                      </span>
                      <span className="flex items-center gap-1">
                        <span className="muted text-xs tabular">{child.products_count ?? 0}</span>
                        <Button
                          variant="ghost"
                          size="sm"
                          aria-label={t.common.edit}
                          onClick={() => openEdit(child)}
                        >
                          <Pencil size={13} />
                        </Button>
                        <ConfirmButton
                          label={t.common.delete}
                          confirmLabel={t.admin.confirmDelete}
                          onConfirm={() => destroy.mutate(child.id)}
                        >
                          <Trash2 size={13} className="text-danger" />
                        </ConfirmButton>
                      </span>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}
