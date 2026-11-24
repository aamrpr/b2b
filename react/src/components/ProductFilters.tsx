import { useEffect, useRef, useState, type ReactNode } from 'react'
import { ChevronDown, SlidersHorizontal } from 'lucide-react'
import clsx from 'clsx'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { Card } from '@/components/ui'

export interface FacetCategory {
  slug: string
  name: string
  count: number
  children: { slug: string; name: string; count: number }[]
}

export interface FacetChoice {
  name: string
  count: number
  key?: string
}

export interface FacetAttribute {
  name: string
  key: string
  values: { name: string; key: string; count: number }[]
}

export interface PriceFacet {
  min: number
  max: number
  currency: string
  decimals: number
}

export interface ProductFacets {
  categories: FacetCategory[]
  brands: FacetChoice[]
  cities: FacetChoice[]
  attributes?: FacetAttribute[]
  price: PriceFacet
}

/** يفصل مفتاح الخاصية عن مفتاح قيمتها في رابط التصفية. */
export const ATTR_SEP = '\u001f'
export const ATTR_JOIN = '\u001e'

export function attrToken(labelKey: string, valueKey: string) {
  return `${labelKey}${ATTR_SEP}${valueKey}`
}

/**
 * عمود التصفية في سوق المنتجات.
 *
 * الأصناف شجرة بعدّاد، والسعر شريط بين حدّين، ثم الماركة والمدينة
 * فالخصائص. العدّاد يأتي من الخادم بعد سائر التصفية، فيبقى الاختيار ممكناً.
 */
export function ProductFilters({
  facets,
  categories,
  brands,
  cities,
  attributes,
  priceMin,
  priceMax,
  onToggle,
  onAttribute,
  onPrice,
  onClear,
  active,
}: {
  facets?: ProductFacets
  categories: string[]
  brands: string[]
  cities: string[]
  attributes: string[]
  priceMin: number | null
  priceMax: number | null
  onToggle: (key: 'category' | 'brand' | 'city', value: string) => void
  onAttribute: (token: string) => void
  onPrice: (min: number | null, max: number | null) => void
  onClear: () => void
  active: boolean
}) {
  const { t } = useI18n()
  const [open, setOpen] = useState(false)

  return (
    <div>
      <button
        type="button"
        className="mb-3 inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-[var(--surface-raised)] px-3 py-2 text-sm font-medium lg:hidden"
        onClick={() => setOpen((value) => !value)}
      >
        <SlidersHorizontal size={15} />
        {open ? t.products.hideFilters : t.products.filters}
      </button>

      <aside
        className={clsx(
          'lg:sticky lg:top-20 lg:block lg:max-h-[calc(100dvh-6rem)] lg:overflow-y-auto',
          open ? 'block' : 'hidden',
        )}
      >
        <Card className="p-0 px-4">
          {!facets ? (
            <p className="py-4 text-sm muted">{t.common.loading}</p>
          ) : (
            <>
              <Section title={t.nav.categories}>
                <CategoryList
                  categories={facets.categories.filter(
                    (category) =>
                      category.count > 0 ||
                      categories.includes(category.slug) ||
                      category.children.some((child) => categories.includes(child.slug)),
                  )}
                  selected={categories}
                  onToggle={(slug) => onToggle('category', slug)}
                />
              </Section>

              <Section title={t.products.price}>
                <PriceRange bounds={facets.price} selectedMin={priceMin} selectedMax={priceMax} onChange={onPrice} />
              </Section>

              {facets.brands.length > 0 && (
                <Section title={t.suppliers.brands}>
                  <ChoiceList
                    choices={facets.brands}
                    selected={brands}
                    onToggle={(name) => onToggle('brand', name)}
                  />
                </Section>
              )}

              {facets.cities.length > 0 && (
                <Section title={t.common.city}>
                  <ChoiceList
                    choices={facets.cities}
                    selected={cities}
                    onToggle={(name) => onToggle('city', name)}
                  />
                </Section>
              )}

              {(facets.attributes?.length ?? 0) > 0 && (
                <Section title={t.products.specifications}>
                  <ul>
                    {facets.attributes?.map((group) => (
                      <AttributeGroup
                        key={group.key}
                        group={group}
                        selected={attributes}
                        onToggle={onAttribute}
                      />
                    ))}
                  </ul>
                </Section>
              )}

              {active && (
                <button
                  type="button"
                  className="mb-4 mt-1 text-sm font-medium text-accent hover:underline"
                  onClick={onClear}
                >
                  {t.products.clearFilters}
                </button>
              )}
            </>
          )}
        </Card>
      </aside>
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  const [open, setOpen] = useState(true)

  return (
    <div className="border-b border-[var(--border)] py-4 last:border-b-0">
      <button
        type="button"
        className="flex w-full items-center justify-between gap-3 text-sm font-semibold"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        {title}
        <ChevronDown size={16} className={clsx('shrink-0 text-[var(--muted)] transition', open && 'rotate-180')} />
      </button>
      {open && <div className="mt-3">{children}</div>}
    </div>
  )
}

function CategoryList({
  categories,
  selected,
  onToggle,
}: {
  categories: FacetCategory[]
  selected: string[]
  onToggle: (slug: string) => void
}) {
  const tree = categories.some((category) => category.children.some((child) => child.count > 0))

  return (
    <ul className="grid">
      {categories.map((category) => (
        <CategoryRow key={category.slug} category={category} selected={selected} onToggle={onToggle} tree={tree} />
      ))}
    </ul>
  )
}

function CategoryRow({
  category,
  selected,
  onToggle,
  tree,
}: {
  category: FacetCategory
  selected: string[]
  onToggle: (slug: string) => void
  tree: boolean
}) {
  const [opened, setOpened] = useState<boolean | null>(null)
  const branch = category.children.filter((child) => child.count > 0)
  const childSelected = branch.some((child) => selected.includes(child.slug))
  const expanded = opened ?? (selected.includes(category.slug) || childSelected)

  return (
    <li>
      <div className="flex items-center gap-1">
        {branch.length > 0 ? (
          <button
            type="button"
            className="grid h-7 w-7 shrink-0 place-items-center rounded-md text-[var(--muted)] hover:bg-[var(--surface)]"
            aria-expanded={expanded}
            onClick={() => setOpened(!expanded)}
          >
            <ChevronDown size={14} className={clsx('transition', expanded && 'rotate-180')} />
          </button>
        ) : tree ? (
          <span className="w-7 shrink-0" />
        ) : null}
        <CheckRow
          label={category.name}
          count={category.count}
          checked={selected.includes(category.slug)}
          onChange={() => onToggle(category.slug)}
        />
      </div>

      {expanded && branch.length > 0 && (
        <ul className="ps-7">
          {branch.map((child) => (
            <li key={child.slug}>
              <CheckRow
                label={child.name}
                count={child.count}
                checked={selected.includes(category.slug) || selected.includes(child.slug)}
                disabled={selected.includes(category.slug)}
                onChange={() => onToggle(child.slug)}
              />
            </li>
          ))}
        </ul>
      )}
    </li>
  )
}

function AttributeGroup({
  group,
  selected,
  onToggle,
}: {
  group: FacetAttribute
  selected: string[]
  onToggle: (token: string) => void
}) {
  const values = group.values.filter(
    (value) => value.count > 0 || selected.includes(attrToken(group.key, value.key)),
  )
  const chosen = values.some((value) => selected.includes(attrToken(group.key, value.key)))
  const [opened, setOpened] = useState<boolean | null>(null)
  const open = opened ?? chosen

  if (values.length === 0) return null

  return (
    <li className="border-b border-[var(--border)] py-2 last:border-b-0">
      <button
        type="button"
        className="flex w-full items-center justify-between gap-3 py-1 text-sm"
        aria-expanded={open}
        onClick={() => setOpened(!open)}
      >
        <span className="min-w-0 truncate" dir="auto">
          {group.name}
        </span>
        <ChevronDown size={14} className={clsx('shrink-0 text-[var(--muted)] transition', open && 'rotate-180')} />
      </button>
      {open && (
        <ChoiceList
          choices={values.map((value) => ({
            name: value.name,
            count: value.count,
            key: attrToken(group.key, value.key),
          }))}
          selected={selected}
          onToggle={onToggle}
        />
      )}
    </li>
  )
}

function ChoiceList({
  choices,
  selected,
  onToggle,
}: {
  choices: FacetChoice[]
  selected: string[]
  onToggle: (name: string) => void
}) {
  return (
    <ul>
      {choices.map((choice) => {
        const id = choice.key ?? choice.name

        return (
          <li key={id}>
            <CheckRow
              label={choice.name}
              count={choice.count}
              checked={selected.includes(id)}
              onChange={() => onToggle(id)}
            />
          </li>
        )
      })}
    </ul>
  )
}

function CheckRow({
  label,
  count,
  checked,
  disabled,
  onChange,
}: {
  label: string
  count: number
  checked: boolean
  disabled?: boolean
  onChange: () => void
}) {
  return (
    <label className={clsx('flex min-w-0 flex-1 cursor-pointer items-center gap-2 py-1 text-sm', disabled && 'cursor-default')}>
      <input
        type="checkbox"
        className="size-4 shrink-0 rounded"
        style={{ accentColor: 'var(--accent)' }}
        checked={checked}
        disabled={disabled}
        onChange={onChange}
      />
      <span className="min-w-0 flex-1 truncate" dir="auto">
        {label}
      </span>
      <span className="muted text-xs tabular">({count})</span>
    </label>
  )
}

function PriceRange({
  bounds,
  selectedMin,
  selectedMax,
  onChange,
}: {
  bounds: PriceFacet
  selectedMin: number | null
  selectedMax: number | null
  onChange: (min: number | null, max: number | null) => void
}) {
  const { t, locale } = useI18n()
  const factor = 10 ** bounds.decimals
  const floor = Math.floor(bounds.min / factor)
  const ceil = Math.max(floor, Math.ceil(bounds.max / factor))

  const clamp = (minor: number | null, edge: number) =>
    minor == null ? edge : Math.min(ceil, Math.max(floor, Math.round(minor / factor)))

  const [low, setLow] = useState(() => clamp(selectedMin, floor))
  const [high, setHigh] = useState(() => clamp(selectedMax, ceil))
  const sent = useRef('')

  useEffect(() => {
    setLow(clamp(selectedMin, floor))
    setHigh(clamp(selectedMax, ceil))
    // المزامنة من الرابط فقط، لا من حركة الإبهام
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedMin, selectedMax, floor, ceil])

  useEffect(() => {
    if (ceil <= floor) return

    const full = low <= floor && high >= ceil
    const next = full ? '' : `${toMinor(low, bounds.decimals)}|${toMinor(high, bounds.decimals)}`
    if (sent.current === next) return

    const timer = window.setTimeout(() => {
      sent.current = next
      onChange(full ? null : toMinor(low, bounds.decimals), full ? null : toMinor(high, bounds.decimals))
    }, 200)

    return () => window.clearTimeout(timer)
  }, [low, high, floor, ceil, bounds.decimals, onChange])

  if (ceil <= floor) {
    return (
      <p className="text-sm tabular">
        {formatAmount(bounds.min, bounds.currency, bounds.decimals, locale)}
      </p>
    )
  }

  const span = ceil - floor || 1
  const start = ((low - floor) / span) * 100
  const width = ((high - low) / span) * 100

  return (
    <div>
      <div className="price-range">
        <div className="absolute start-0 end-0 top-1/2 h-1 -translate-y-1/2 rounded-full bg-[var(--border)]">
          <div
            className="absolute h-full rounded-full bg-[var(--accent)]"
            style={{ insetInlineStart: `${start}%`, width: `${width}%` }}
          />
        </div>
        <input
          type="range"
          min={floor}
          max={ceil}
          value={low}
          aria-label={t.products.priceFrom}
          onChange={(event) => setLow(Math.min(Number(event.target.value), high))}
        />
        <input
          type="range"
          min={floor}
          max={ceil}
          value={high}
          aria-label={t.products.priceTo}
          onChange={(event) => setHigh(Math.max(Number(event.target.value), low))}
        />
      </div>
      <p className="mt-2 text-center text-xs muted tabular">
        {t.products.priceFrom} {formatAmount(toMinor(low, bounds.decimals), bounds.currency, bounds.decimals, locale)}
        {' '}
        {t.products.priceTo} {formatAmount(toMinor(high, bounds.decimals), bounds.currency, bounds.decimals, locale)}
      </p>
    </div>
  )
}
