import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Plus, Trash2, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatMoney, toMinor } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Field, Input, PageTitle, Select, Textarea } from '@/components/ui'
import type { Category, Product, Translations } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'
import { categoryOptions } from '@/lib/options'
import { useUnits } from '@/lib/units'

export function MyProducts() {
  const { t, locale } = useI18n()
  const { units, label: unitLabel } = useUnits()
  const [open, setOpen] = useState(false)
  /** المنتج قيد التعديل، وnull يعني منتجاً جديداً */
  const [editing, setEditing] = useState<Product | null>(null)
  const [error, setError] = useState<string | null>(null)
  /*
   * الخصائص بلغة المستورد وحدها: النموذج عربيّ أصلاً كالاسم، والخادم
   * يملأ الإنجليزية منها فلا تختفي الخاصّية عن قارئٍ إنجليزيّ.
   *
   * وtranslations هي النصّ باللغتين كما جاء من الخادم، يُحتفظ به ليعود
   * معه عند الحفظ: بدونه يمحو كلُّ تعديلٍ النسخةَ الإنجليزية ويضع العربية
   * مكانها، فينقلب التعديل حذفاً للترجمة.
   */
  const [specs, setSpecs] = useState<
    { label: string; value: string; translations?: { label: Translations; value: Translations } }[]
  >([])

  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data,
  })

  const [reloadKey, setReloadKey] = useState(0)
  /** صور المنتج المحفوظة التي ستبقى بعد الحفظ */
  const [kept, setKept] = useState<{ id: number; url: string; thumb_url?: string | null }[]>([])
  /** صور اختيرت ولم تُرفع بعد */
  const [pending, setPending] = useState<{ file: File; url: string }[]>([])
  const [removed, setRemoved] = useState<number[]>([])
  /** معرّف منتج أُنشئ ثم تعذّر رفع صوره، كي لا تُنشأ نسخة ثانية عند الإعادة */
  const savedId = useRef<number | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const reload = () => setReloadKey((key) => key + 1)

  const save = useMutation({
    mutationFn: async ({
      id,
      payload,
      files,
      removeIds,
    }: {
      id: number | null
      payload: Record<string, unknown>
      files: File[]
      removeIds: number[]
    }) => {
      let productId = id

      if (productId) {
        await api.put(`/my/products/${productId}`, payload)
      } else {
        const { data } = await api.post<{ data: Product }>('/my/products', payload)
        productId = data.data.id
        savedId.current = productId
      }

      for (const mediaId of removeIds) {
        await api.delete(`/my/products/${productId}/images/${mediaId}`)
        setRemoved((ids) => ids.filter((item) => item !== mediaId))
        setKept((images) => images.filter((image) => image.id !== mediaId))
      }

      if (files.length > 0) {
        const form = new FormData()
        files.forEach((file) => form.append('images[]', file))
        await api.post(`/my/products/${productId}/images`, form)
      }
    },
    onSuccess: () => closeForm(),
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const remove = useMutation({
    mutationFn: async (id: number) => (await api.delete(`/my/products/${id}`)).data,
    onSuccess: reload,
  })

  function forgetPending(files: { url: string }[]) {
    files.forEach((item) => URL.revokeObjectURL(item.url))
  }

  function closeForm() {
    setPending((files) => {
      forgetPending(files)
      return []
    })
    setKept([])
    setRemoved([])
    savedId.current = null
    setOpen(false)
    setEditing(null)
    setSpecs([])
    setError(null)
    reload()
  }

  function openCreate() {
    setPending((files) => {
      forgetPending(files)
      return []
    })
    setKept([])
    setRemoved([])
    savedId.current = null
    setEditing(null)
    setSpecs([])
    setError(null)
    setOpen(true)
  }

  /*
   * الخصائص تأتي من النسخة الكاملة لا المعروضة: المعروضة بلغة القارئ
   * فقط، ولو حُفظت عنها لحلّت العربية محلّ الإنجليزية أو بالعكس.
   */
  function openEdit(product: Product) {
    setPending((files) => {
      forgetPending(files)
      return []
    })
    setKept(product.images ?? [])
    setRemoved([])
    savedId.current = product.id
    setEditing(product)
    setSpecs(
      (product.specifications_translations ?? []).map((spec) => ({
        label: spec.label.ar ?? '',
        value: spec.value.ar ?? '',
        translations: spec,
      })),
    )
    setError(null)
    setOpen(true)
  }

  /**
   * النصّ العربي الجديد مع ما سبقه من ترجمات.
   *
   * تبقى الإنجليزية ما دام العربيّ كما هو — تعديل السعر لا يُفترض أن يمحو
   * ترجمةً — فإن تبدّل العربيّ سقطت، لأنّها حينئذٍ ترجمةُ نصٍّ آخر،
   * ويتولّى الخادم ملأها من العربية.
   */
  function withTranslations(arabic: string, previous?: Translations): Translations {
    return previous && previous.ar === arabic ? { ...previous, ar: arabic } : { ar: arabic }
  }

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const form = new FormData(event.currentTarget)
    const description = String(form.get('description') ?? '').trim()
    const previousDescription = editing?.description_translations

    const payload = {
      category_id: Number(form.get('category_id')),
      name: { ar: form.get('name_ar'), en: form.get('name_en') || form.get('name_ar') },
      description: description ? withTranslations(description, previousDescription) : null,
      sku: form.get('sku') || null,
      brand: form.get('brand') || null,
      unit_of_measure: form.get('unit') || 'piece',
      min_order_quantity: Number(form.get('moq') || 1),
      lead_time_days: Number(form.get('lead_time') || 0),
      base_price: toMinor(String(form.get('price') ?? '0')),
      status: String(form.get('status') || 'active'),
      specifications: specs
        .filter((spec) => spec.label.trim() && spec.value.trim())
        .map((spec) => ({
          label: withTranslations(spec.label.trim(), spec.translations?.label),
          value: withTranslations(spec.value.trim(), spec.translations?.value),
        })),
    }

    save.mutate({
      id: editing?.id ?? savedId.current,
      payload,
      files: pending.map((item) => item.file),
      removeIds: removed,
    })
  }

  function addFiles(list: FileList | null) {
    if (!list?.length) return

    const room = 8 - pending.length
    const chosen = Array.from(list).slice(0, Math.max(room, 0))

    if (chosen.length < list.length) setError(t.products.imagesLimit)
    else setError(null)

    setPending((current) => [
      ...current,
      ...chosen.map((file) => ({ file, url: URL.createObjectURL(file) })),
    ])
  }

  const busy = save.isPending
  const closeRef = useRef(closeForm)
  closeRef.current = closeForm

  useEffect(() => {
    if (!open) return

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
  }, [open, busy])

  const columns: DataTableColumn<Product>[] = [
    {
      data: 'images',
      title: t.products.images,
      orderable: false,
      searchable: false,
      export: false,
      /* الصورة تبقى ظاهرة، ومؤشّر الطي لا يُرسم فوقها */
      className: 'all dt-thumb',
      priority: 1,
      render: (row) => {
        const image = row.images?.[0]

        if (!image) return ''

        return `<img src="${esc(image.thumb_url || image.url)}" alt="${esc(row.name)}" class="h-12 w-12 rounded-lg object-cover">`
      },
    },
    {
      data: 'sku',
      title: 'SKU',
      className: 'dt-num dt-ltr muted',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(row.sku ?? '\u2014'),
    },
    {
      data: 'name',
      title: t.accounting.account,
      className: 'font-medium',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.name)}</span>`,
    },
    {
      data: 'price',
      title: t.common.unitPrice,
      className: 'dt-num',
      priority: 1,
      filter: { type: 'text' },
      render: (row) => esc(formatMoney(row.price, locale)),
    },
    {
      data: 'min_order_quantity',
      title: t.suppliers.minOrder,
      className: 'dt-num',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => `${esc(row.min_order_quantity)} ${esc(unitLabel(row.unit_of_measure))}`,
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 2,
      filter: {
        type: 'select',
        options: (['active', 'draft', 'out_of_stock', 'archived'] as const).map((value) => ({
          value,
          label: t.products.statuses[value],
        })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.status === 'active' ? 'dt-chip-success' : 'dt-chip-neutral'}">${esc(
          t.products.statuses[row.status as keyof typeof t.products.statuses] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      render: (row) =>
        `<button type="button" class="dt-btn" data-action="edit" data-id="${row.id}">${esc(t.common.edit)}</button>` +
        `<button type="button" class="dt-btn dt-btn-danger" data-action="delete" data-id="${row.id}" data-confirm="${esc(t.admin.confirmDelete)}">${esc(t.common.delete)}</button>`,
    },
  ]

  return (
    <div>
      <PageTitle
        title={t.nav.myProducts}
        action={
          <Button size="sm" onClick={openCreate}>
            <Plus size={15} />
            {t.products.newProduct}
          </Button>
        }
      />

      {open && (
        <div
          className="fixed inset-0 z-[70] flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !busy) closeForm()
          }}
        >
          {/*
            * key يُجبر النموذج على إعادة البناء عند تبدّل المنتج: الحقول
            * غير مضبوطة بالحالة، فبدونه تبقى قيم المنتج السابق معروضة.
            */}
          <form
            key={editing?.id ?? savedId.current ?? 'new'}
            role="dialog"
            aria-modal="true"
            aria-labelledby="product-form-title"
            onSubmit={submit}
            onMouseDown={(event) => event.stopPropagation()}
            className="card my-auto grid w-full max-w-3xl gap-4 p-5 sm:grid-cols-2"
          >
            <div className="sm:col-span-2 flex items-center justify-between gap-3">
              <h2 id="product-form-title" className="text-base font-semibold">
                {editing ? t.products.editProduct : t.products.newProduct}
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
              <Input name="name_ar" required defaultValue={editing?.name_translations?.ar ?? ''} />
            </Field>
            <Field label={t.common.nameEn}>
              <Input name="name_en" dir="ltr" defaultValue={editing?.name_translations?.en ?? ''} />
            </Field>
            <Field label={t.rfq.category}>
              <SearchSelect
                name="category_id"
                required
                defaultValue={editing ? String(editing.category_id) : undefined}
                options={categoryOptions(categories)}
              />
            </Field>
            <Field label={t.products.sku}>
              <Input name="sku" dir="ltr" defaultValue={editing?.sku ?? ''} />
            </Field>
            <Field label={t.suppliers.brands}>
              <Input name="brand" defaultValue={editing?.brand ?? ''} />
            </Field>
            <Field label={t.common.unit}>
              <SearchSelect
                key={`${editing?.id ?? 'new'}-${units.length}`}
                name="unit"
                required
                defaultValue={editing?.unit_of_measure ?? 'piece'}
                options={units
                  .filter((unit) => unit.is_active || unit.code === (editing?.unit_of_measure ?? 'piece'))
                  .map((unit) => ({ value: unit.code, label: unit.name }))}
              />
            </Field>
            <Field label={t.suppliers.minOrder}>
              <Input
                name="moq"
                type="number"
                min={1}
                className="tabular"
                defaultValue={editing?.min_order_quantity ?? 1}
              />
            </Field>
            <Field label={`${t.quotation.leadTime} (${t.quotation.days})`}>
              <Input
                name="lead_time"
                type="number"
                min={0}
                className="tabular"
                defaultValue={editing?.lead_time_days ?? 7}
              />
            </Field>
            <Field label={t.common.unitPrice}>
              <Input
                name="price"
                type="number"
                step="0.01"
                min="0"
                className="tabular"
                required
                defaultValue={editing?.price ? editing.price.amount / 10 ** editing.price.decimals : ''}
              />
            </Field>
            <Field label={t.common.status}>
              <Select name="status" defaultValue={editing?.status ?? 'active'}>
                {(['active', 'draft', 'out_of_stock', 'archived'] as const).map((status) => (
                  <option key={status} value={status}>
                    {t.products.statuses[status]}
                  </option>
                ))}
              </Select>
            </Field>

            <div className="sm:col-span-2">
              <Field label={t.products.description}>
                <Textarea name="description" rows={3} defaultValue={editing?.description_translations?.ar ?? ''} />
              </Field>
            </div>

            <div className="sm:col-span-2">
              <div className="mb-2 flex flex-wrap items-end justify-between gap-3">
                <div>
                  <div className="text-sm font-medium">{t.products.specifications}</div>
                  <p className="muted mt-0.5 text-xs">{t.products.specsHint}</p>
                </div>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => setSpecs((rows) => [...rows, { label: '', value: '' }])}
                >
                  <Plus size={14} />
                  {t.products.addSpec}
                </Button>
              </div>

              <div className="grid gap-2">
                {specs.map((spec, index) => (
                  <div key={index} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                    <Input
                      placeholder={t.products.specLabel}
                      // dir="auto" ليظهر رقم القطعة اللاتيني كما يُكتب لا مقلوباً
                      dir="auto"
                      value={spec.label}
                      onChange={(event) =>
                        setSpecs((rows) =>
                          rows.map((row, i) => (i === index ? { ...row, label: event.target.value } : row)),
                        )
                      }
                    />
                    <Input
                      placeholder={t.products.specValue}
                      dir="auto"
                      value={spec.value}
                      onChange={(event) =>
                        setSpecs((rows) =>
                          rows.map((row, i) => (i === index ? { ...row, value: event.target.value } : row)),
                        )
                      }
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      aria-label={t.common.cancel}
                      onClick={() => setSpecs((rows) => rows.filter((_, i) => i !== index))}
                    >
                      <Trash2 size={15} />
                    </Button>
                  </div>
                ))}
              </div>
            </div>

            <div className="sm:col-span-2">
              <div className="mb-2">
                <div className="text-sm font-medium">{t.products.images}</div>
                <p className="muted mt-0.5 text-xs">{t.products.imagesHint}</p>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                {kept.map((image) => (
                  <span key={image.id} className="relative inline-block">
                    <img src={image.thumb_url || image.url} alt="" className="h-20 w-20 rounded-lg object-cover" />
                    <button
                      type="button"
                      title={t.common.delete}
                      aria-label={t.common.delete}
                      onClick={() => {
                        setKept((images) => images.filter((item) => item.id !== image.id))
                        setRemoved((ids) => [...ids, image.id])
                      }}
                      className="absolute -top-1.5 -end-1.5 grid h-5 w-5 place-items-center rounded-full bg-red-600 text-xs leading-none text-white"
                    >
                      ×
                    </button>
                  </span>
                ))}

                {pending.map((item) => (
                  <span key={item.url} className="relative inline-block">
                    <img src={item.url} alt="" className="h-20 w-20 rounded-lg object-cover" />
                    <button
                      type="button"
                      title={t.common.delete}
                      aria-label={t.common.delete}
                      onClick={() => {
                        URL.revokeObjectURL(item.url)
                        setPending((files) => files.filter((file) => file.url !== item.url))
                      }}
                      className="absolute -top-1.5 -end-1.5 grid h-5 w-5 place-items-center rounded-full bg-red-600 text-xs leading-none text-white"
                    >
                      ×
                    </button>
                  </span>
                ))}

                <Button type="button" variant="outline" size="sm" onClick={() => fileInput.current?.click()}>
                  <Plus size={14} />
                  {t.products.addImages}
                </Button>
              </div>

              <input
                ref={fileInput}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                multiple
                className="hidden"
                onChange={(event) => {
                  addFiles(event.target.files)
                  event.target.value = ''
                }}
              />
            </div>

            <div className="sm:col-span-2 flex gap-3">
              <Button type="submit" disabled={busy}>
                {busy ? t.common.loading : t.common.save}
              </Button>
              <Button type="button" variant="ghost" onClick={closeForm} disabled={busy}>
                {t.common.cancel}
              </Button>
            </div>

            {error && <p className="sm:col-span-2 text-sm text-danger">{error}</p>}
          </form>
        </div>
      )}

      <DataTable<Product>
        url="/my/products"
        exportName="my-products"
        columns={columns}
        order={['id', 'desc']}
        reloadKey={reloadKey}
        emptyMessage={t.common.empty}
        onAction={(action, id, _value, row) => {
          if (action === 'edit' && row) openEdit(row)
          if (action === 'delete') remove.mutate(Number(id))
        }}
      />
    </div>
  )
}
