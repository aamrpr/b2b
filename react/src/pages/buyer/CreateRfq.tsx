import { useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Plus, Trash2, Users } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { Button, Card, Field, Input, PageTitle, Textarea } from '@/components/ui'
import type { Category, Product, Rfq } from '@/types'
import { SearchSelect } from '@/components/fields/SearchSelect'
import { categoryOptions } from '@/lib/options'
import { useUnits } from '@/lib/units'

interface DraftItem {
  productId: string
  description: string
  quantity: number
  unit: string
}

function findCategory(categories: Category[] | undefined, id: string): Category | undefined {
  for (const category of categories ?? []) {
    if (String(category.id) === id) return category
    const child = category.children?.find((item) => String(item.id) === id)
    if (child) return child
  }

  return undefined
}

/** اسمٌ واحد في القائمة: البطارية نفسها عند ثلاثة مستوردين ليست ثلاثة منتجات */
function uniqueByName(products: Product[], preferredIds: string[]): Product[] {
  const preferred = new Set(preferredIds)
  const winner = new Map<string, Product>()

  for (const product of products) {
    const key = product.name.trim().toLocaleLowerCase()
    const current = winner.get(key)

    if (!current || preferred.has(String(product.id))) winner.set(key, product)
  }

  const seen = new Set<string>()
  const result: Product[] = []

  for (const product of products) {
    const key = product.name.trim().toLocaleLowerCase()
    if (seen.has(key)) continue

    seen.add(key)
    const chosen = winner.get(key)
    if (chosen) result.push(chosen)
  }

  return result
}

/** أصناف الفرع المختار: الصنف نفسه وأبناؤه، كما يصفّيها كتالوج المنتجات */
function branchIds(categories: Category[] | undefined, id: string): number[] {
  const category = findCategory(categories, id)
  if (!category) return []

  return [category.id, ...(category.children ?? []).map((child) => child.id)]
}

/** المسار (ب): تاجر الجملة ينشر طلباً فيصل إلى كل المستوردين في الصنف. */
export function CreateRfq() {
  const { t } = useI18n()
  const { label: unitLabel } = useUnits()
  const navigate = useNavigate()

  /*
   * القادم من صفحة منتج يجد الطلب مربوطاً بذلك المنتج — صنفه وكميته
   * ووحدته — لا منسوخاً اسماً في حقل نصّ.
   */
  const [params] = useSearchParams()
  const presetSlug = params.get('slug') ?? ''

  const [title, setTitle] = useState(params.get('item') ?? '')
  const [description, setDescription] = useState('')
  const [categoryId, setCategoryId] = useState(params.get('category') ?? '')
  const [deliveryCity, setDeliveryCity] = useState('')
  const [validityHours, setValidityHours] = useState(72)
  const [hideIdentity, setHideIdentity] = useState(false)
  const [items, setItems] = useState<DraftItem[]>([
    {
      productId: params.get('product') ?? '',
      description: params.get('item') ?? '',
      quantity: Number(params.get('qty')) || 1,
      unit: params.get('unit') || 'piece',
    },
  ])
  const [error, setError] = useState<string | null>(null)
  const [reached, setReached] = useState<number | null>(null)

  const { data: categories } = useQuery({
    queryKey: ['categories'],
    queryFn: async () => (await api.get<{ data: Category[] }>('/categories')).data.data,
  })

  const categorySlug = findCategory(categories, categoryId)?.slug

  const { data: listed, isFetching } = useQuery({
    queryKey: ['rfq-products', categorySlug],
    enabled: Boolean(categorySlug),
    queryFn: async () =>
      (
        await api.get<{ data: Product[] }>('/products', {
          params: { category: categorySlug, per_page: 100 },
        })
      ).data.data,
  })

  const { data: preset } = useQuery({
    queryKey: ['product', presetSlug],
    enabled: presetSlug !== '',
    queryFn: async () => (await api.get<{ data: Product }>(`/products/${presetSlug}`)).data.data,
  })

  const catalog = useMemo(() => {
    const list = listed ?? []
    const allowed = branchIds(categories, categoryId)
    const merged =
      preset && !list.some((product) => product.id === preset.id) && allowed.includes(preset.category_id)
        ? [preset, ...list]
        : list

    return uniqueByName(
      merged,
      items.map((item) => item.productId),
    )
  }, [listed, preset, categories, categoryId, items])

  const mutation = useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ reached_suppliers: number; rfq: { data: Rfq } }>('/rfqs', {
        title,
        description: description || undefined,
        category_id: Number(categoryId),
        delivery_city: deliveryCity || undefined,
        validity_hours: validityHours,
        hide_buyer_identity: hideIdentity,
        visibility: 'public',
        items: items.map((item) => {
          const product = catalog.find((entry) => String(entry.id) === item.productId)

          return {
            product_id: Number(item.productId),
            description: product?.name || item.description,
            quantity: Number(item.quantity),
            unit: item.unit || product?.unit_of_measure || 'piece',
          }
        }),
      })
      return data
    },
    onSuccess: (data) => {
      setReached(data.reached_suppliers)
      setTimeout(() => navigate('/my/rfqs'), 2200)
    },
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  function updateItem(index: number, patch: Partial<DraftItem>) {
    setItems((current) => current.map((item, i) => (i === index ? { ...item, ...patch } : item)))
  }

  function chooseProduct(index: number, value: string) {
    const product = catalog.find((entry) => String(entry.id) === value)

    updateItem(index, {
      productId: value,
      description: product?.name ?? '',
      unit: product?.unit_of_measure || 'piece',
      quantity: product?.min_order_quantity || 1,
    })
  }

  if (reached !== null) {
    return (
      <Card className="mx-auto max-w-lg text-center py-12">
        <div className="mx-auto grid h-14 w-14 place-items-center rounded-full bg-[var(--success-soft)] text-success">
          <Users size={24} />
        </div>
        <div className="mt-5 text-2xl font-bold tabular">{reached}</div>
        <p className="muted mt-1">
          {t.rfq.reach} {reached} {t.rfq.supplier}
        </p>
      </Card>
    )
  }

  return (
    <div className="mx-auto max-w-3xl">
      <PageTitle title={t.rfq.create} subtitle={t.home.step2d} />

      <form
        onSubmit={(e) => {
          e.preventDefault()

          if (!categoryId || items.some((item) => !item.productId)) {
            setError(t.rfq.productRequired)
            return
          }

          setError(null)
          mutation.mutate()
        }}
        className="space-y-5"
      >
        <Card className="space-y-4">
          <Field label={t.rfq.title}>
            <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder={t.rfq.titlePlaceholder} required />
          </Field>

          <Field label={t.rfq.category}>
            <SearchSelect
              key={categories ? categoryId || 'ready' : 'wait'}
              name="category_id"
              required
              defaultValue={categoryId}
              options={categoryOptions(categories)}
              onChange={(value) => {
                setCategoryId(value)
                setItems((current) =>
                  current.map((item) => ({ ...item, productId: '', description: '' })),
                )
              }}
            />
          </Field>

          <Field label={t.rfq.description}>
            <Textarea value={description} onChange={(e) => setDescription(e.target.value)} />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t.rfq.deliveryCity}>
              <Input value={deliveryCity} onChange={(e) => setDeliveryCity(e.target.value)} />
            </Field>

            <Field label={`${t.rfq.validity} (${t.rfq.hours})`}>
              <Input
                type="number"
                min={1}
                max={720}
                value={validityHours}
                onChange={(e) => setValidityHours(Number(e.target.value))}
              />
            </Field>
          </div>

          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={hideIdentity} onChange={(e) => setHideIdentity(e.target.checked)} />
            {t.rfq.hideIdentity}
          </label>
        </Card>

        <Card>
          <div className="flex items-center justify-between mb-4">
            <h2 className="font-semibold">{t.rfq.items}</h2>
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={() =>
                setItems((current) => [...current, { productId: '', description: '', quantity: 1, unit: 'piece' }])
              }
            >
              <Plus size={15} />
              {t.rfq.addItem}
            </Button>
          </div>

          <div className="space-y-3">
            {items.map((item, index) => {
              const taken = new Set(
                items
                  .filter((row, rowIndex) => rowIndex !== index && row.productId)
                  .map((row) => catalog.find((product) => String(product.id) === row.productId)?.name.trim().toLocaleLowerCase())
                  .filter((name): name is string => Boolean(name)),
              )

              return (
                <div key={index} className="flex flex-wrap items-end gap-3">
                  <div className="min-w-48 flex-1">
                    <Field label={t.rfq.product}>
                      <SearchSelect
                        key={`${categoryId}-${index}-${catalog.length}-${[...taken].join()}`}
                        name={`product_${index}`}
                        required
                        placeholder={
                          !categoryId ? t.rfq.pickCategoryFirst : isFetching ? t.common.loading : t.rfq.pickProduct
                        }
                        defaultValue={item.productId}
                        options={catalog
                          .filter((product) => !taken.has(product.name.trim().toLocaleLowerCase()))
                          .map((product) => ({
                            value: String(product.id),
                            label: product.name,
                          }))}
                        onChange={(value) => chooseProduct(index, value)}
                      />
                    </Field>
                  </div>
                  <div className="w-28">
                    <Field label={t.common.quantity}>
                      <Input
                        type="number"
                        min={1}
                        value={item.quantity}
                        onChange={(e) => updateItem(index, { quantity: Number(e.target.value) })}
                        required
                      />
                    </Field>
                  </div>
                  <div className="w-36">
                    <Field label={t.common.unit}>
                      <Input
                        value={item.productId ? unitLabel(item.unit) : ''}
                        readOnly
                        disabled
                        tabIndex={-1}
                        className="cursor-not-allowed opacity-70"
                      />
                    </Field>
                  </div>
                  {items.length > 1 && (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => setItems((current) => current.filter((_, i) => i !== index))}
                    >
                      <Trash2 size={16} className="text-danger" />
                    </Button>
                  )}
                </div>
              )
            })}
          </div>
        </Card>

        {error && <p className="text-sm text-danger">{error}</p>}

        <Button type="submit" disabled={mutation.isPending} className="w-full">
          {mutation.isPending ? t.common.loading : t.rfq.publish}
        </Button>
      </form>
    </div>
  )
}
