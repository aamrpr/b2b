import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus, Trash2, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Badge, Button, Card, ConfirmButton, ErrorState, Field, Input, Loading, Textarea } from '@/components/ui'
import type { Translations } from '@/types'

interface AdminBanner {
  id: number
  eyebrow: string | null
  eyebrow_translations: Translations
  title: string
  title_translations: Translations
  text: string | null
  text_translations: Translations
  href: string
  image_url: string | null
  sort_order: number
  is_active: boolean
}

interface AdminCategory {
  name: string
  slug: string
  children?: AdminCategory[]
}

/**
 * بنرات الصفحة الرئيسية.
 *
 * النص بلغتين، والرابط مسار داخل المنصة، والصورة تُرفع مع الإنشاء
 * وتُستبدل وحدها عند التعديل.
 */
export function AdminBanners() {
  const { t } = useI18n()
  const queryClient = useQueryClient()

  const [editing, setEditing] = useState<AdminBanner | 'new' | null>(null)
  const [href, setHref] = useState('')
  const [preview, setPreview] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['admin-banners'],
    queryFn: async () => (await api.get<{ data: AdminBanner[] }>('/admin/banners')).data.data,
  })

  const categories = useQuery({
    queryKey: ['admin-categories'],
    enabled: editing !== null,
    queryFn: async () => (await api.get<{ data: AdminCategory[] }>('/admin/categories')).data.data,
  })

  function refreshLists() {
    void queryClient.invalidateQueries({ queryKey: ['admin-banners'] })
    void queryClient.invalidateQueries({ queryKey: ['banners'] })
  }

  function closeForm() {
    setEditing(null)
    setPreview(null)
    setError(null)
    refreshLists()
  }

  function openCreate() {
    setError(null)
    setHref('/products')
    setPreview(null)
    setEditing('new')
  }

  function openEdit(banner: AdminBanner) {
    setError(null)
    setHref(banner.href)
    setPreview(null)
    setEditing(banner)
  }

  const save = useMutation({
    mutationFn: async ({ id, form, image }: { id: number | null; form: FormData; image: File | null }) => {
      if (id === null) {
        await api.post('/admin/banners', form)
        return
      }

      await api.put(`/admin/banners/${id}`, {
        eyebrow: { ar: form.get('eyebrow_ar'), en: form.get('eyebrow_en') },
        title: { ar: form.get('title_ar'), en: form.get('title_en') },
        text: { ar: form.get('text_ar'), en: form.get('text_en') },
        href: form.get('href'),
        sort_order: Number(form.get('sort_order') || 0),
        is_active: form.get('is_active') === '1',
      })

      if (image) {
        const picture = new FormData()
        picture.append('image', image)
        await api.post(`/admin/banners/${id}/image`, picture)
      }
    },
    onSuccess: () => closeForm(),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const destroy = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/admin/banners/${id}`)).data,
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

  useEffect(() => {
    return () => {
      if (preview) URL.revokeObjectURL(preview)
    }
  }, [preview])

  if (isLoading) return <Loading message={t.common.loading} />
  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  const banners = data ?? []
  const current = editing === 'new' ? null : editing
  const choices = (categories.data ?? []).flatMap((category) => [
    category,
    ...(category.children ?? []),
  ])

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    form.set('is_active', form.get('is_active') === 'on' ? '1' : '0')
    form.set('href', href)
    const image = form.get('image')
    const file = image instanceof File && image.size > 0 ? image : null

    if (editing === 'new' && !file) {
      setError(t.admin.bannerImage)
      return
    }

    if (editing === 'new') {
      form.set('eyebrow[ar]', String(form.get('eyebrow_ar') ?? ''))
      form.set('eyebrow[en]', String(form.get('eyebrow_en') ?? ''))
      form.set('title[ar]', String(form.get('title_ar') ?? ''))
      form.set('title[en]', String(form.get('title_en') ?? ''))
      form.set('text[ar]', String(form.get('text_ar') ?? ''))
      form.set('text[en]', String(form.get('text_en') ?? ''))
    }

    save.mutate({ id: editing === 'new' ? null : (editing?.id ?? null), form, image: file })
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-3">
        <Button size="sm" onClick={openCreate}>
          <Plus size={15} />
          {t.admin.newBanner}
        </Button>
        <span className="muted text-sm tabular">{banners.length}</span>
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
            aria-labelledby="banner-form-title"
            onSubmit={submit}
            onMouseDown={(event) => event.stopPropagation()}
            className="card my-auto grid w-full max-w-2xl gap-4 p-5 sm:grid-cols-2"
          >
            <div className="flex items-center justify-between gap-3 sm:col-span-2">
              <h2 id="banner-form-title" className="text-base font-semibold">
                {current ? t.common.edit : t.admin.newBanner}
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

            <Field label={`${t.admin.bannerEyebrow} · ${t.common.nameAr}`}>
              <Input name="eyebrow_ar" defaultValue={current?.eyebrow_translations?.ar ?? ''} maxLength={80} />
            </Field>
            <Field label={`${t.admin.bannerEyebrow} · ${t.common.nameEn}`}>
              <Input name="eyebrow_en" dir="ltr" defaultValue={current?.eyebrow_translations?.en ?? ''} maxLength={80} />
            </Field>
            <Field label={`${t.admin.bannerTitle} · ${t.common.nameAr}`}>
              <Input name="title_ar" required defaultValue={current?.title_translations?.ar ?? ''} maxLength={160} />
            </Field>
            <Field label={`${t.admin.bannerTitle} · ${t.common.nameEn}`}>
              <Input name="title_en" dir="ltr" defaultValue={current?.title_translations?.en ?? ''} maxLength={160} />
            </Field>
            <Field label={`${t.admin.bannerText} · ${t.common.nameAr}`} className="sm:col-span-2">
              <Textarea name="text_ar" defaultValue={current?.text_translations?.ar ?? ''} maxLength={300} />
            </Field>
            <Field label={`${t.admin.bannerText} · ${t.common.nameEn}`} className="sm:col-span-2">
              <Textarea name="text_en" dir="ltr" defaultValue={current?.text_translations?.en ?? ''} maxLength={300} />
            </Field>

            <Field label={t.admin.bannerCategory}>
              <select
                className="w-full rounded-lg border border-[var(--border)] bg-[var(--surface-raised)] px-3 py-2.5 text-sm"
                value=""
                onChange={(event) => {
                  if (event.target.value) setHref(`/products?category=${event.target.value}`)
                }}
              >
                <option value="">{t.admin.bannerCategory}</option>
                {choices.map((category) => (
                  <option key={category.slug} value={category.slug}>
                    {category.name}
                  </option>
                ))}
              </select>
            </Field>
            <Field label={t.admin.sortOrder}>
              <Input
                name="sort_order"
                type="number"
                min={0}
                className="tabular"
                defaultValue={current?.sort_order ?? banners.length}
              />
            </Field>
            <Field label={t.admin.bannerLink} hint={t.admin.bannerLinkHint} className="sm:col-span-2">
              <Input name="href" required dir="ltr" value={href} onChange={(event) => setHref(event.target.value)} />
            </Field>

            <Field label={t.admin.bannerImage} hint={t.admin.bannerImageHint} className="sm:col-span-2">
              <Input
                name="image"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                onChange={(event) => {
                  const file = event.target.files?.[0]
                  setPreview(file ? URL.createObjectURL(file) : null)
                }}
              />
              {(preview || current?.image_url) && (
                <img
                  src={preview ?? current?.image_url ?? ''}
                  alt=""
                  className="mt-3 h-36 w-full rounded-xl object-cover"
                />
              )}
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

      <div className="grid gap-3 sm:grid-cols-2">
        {banners.map((banner) => (
          <Card key={banner.id} className="overflow-hidden p-0">
            {banner.image_url && (
              <img src={banner.image_url} alt="" className="h-36 w-full object-cover" />
            )}
            <div className="p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="font-semibold">
                    <span dir="auto">{banner.title}</span>
                  </div>
                  <div className="muted mt-0.5 truncate text-xs" dir="ltr">
                    {banner.href}
                  </div>
                </div>
                {!banner.is_active && <Badge tone="neutral">{t.admin.disabled}</Badge>}
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                <Button variant="ghost" size="sm" onClick={() => openEdit(banner)}>
                  <Pencil size={15} />
                  {t.common.edit}
                </Button>
                <ConfirmButton
                  label={t.common.delete}
                  confirmLabel={t.admin.confirmDelete}
                  onConfirm={() => destroy.mutate(banner.id)}
                >
                  <Trash2 size={15} className="text-danger" />
                </ConfirmButton>
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}
