import { useState } from 'react'
import { useI18n } from '@/i18n'
import { formatAmount } from '@/lib/money'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import type { StockMovement } from '@/types'

/**
 * سجلّ حركات المخزون.
 *
 * والحركة لا تُعدَّل بعد تسجيلها — الخطأ يُصحَّح بحركةٍ مقابلة — فهذه
 * الصفحة قراءةٌ محضة: فرزٌ وتصفيةٌ وبحث، بلا زرّ يغيّر سطراً.
 */
export function StockMovements() {
  const { t, locale } = useI18n()
  const [currency, setCurrency] = useState('SYP')

  const money = (value: number) => formatAmount(value, currency, 2, locale)
  const typeLabels = t.inventory.types as Record<string, string>

  const columns: DataTableColumn<StockMovement>[] = [
    {
      data: 'created_at',
      title: t.common.date,
      className: 'dt-num muted text-sm',
      priority: 2,
      filter: { type: 'dates' },
      render: (row) => esc(row.created_at?.slice(0, 10) ?? ''),
    },
    {
      data: 'product',
      title: t.inventory.product,
      orderable: false,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<span class="font-medium" dir="auto">${esc(row.product)}</span>` +
        `<div class="muted text-xs" dir="auto">${esc(row.warehouse)}</div>`,
    },
    {
      data: 'type',
      title: t.inventory.movementType,
      priority: 1,
      filter: {
        type: 'select',
        options: Object.entries(typeLabels).map(([value, label]) => ({ value, label })),
      },
      render: (row) =>
        `<span class="dt-chip ${row.quantity > 0 ? 'dt-chip-success' : 'dt-chip-warning'}">${esc(
          typeLabels[row.type] ?? row.type,
        )}</span>`,
    },
    {
      data: 'quantity',
      title: t.common.quantity,
      filter: { type: 'text' },
      // الإشارة تسبق الرقم: في العربية يقع ‎+‎ آخر السطر ما لم يُعزَل
      className: 'dt-num dt-ltr font-medium',
      priority: 1,
      // الإشارة تُقرأ قبل الرقم: الداخل أخضر بعلامة زائد، والخارج أحمر
      render: (row) =>
        `<span class="${row.quantity > 0 ? 'text-success' : 'text-danger'}">${row.quantity > 0 ? '+' : ''}${esc(row.quantity)}</span>`,
    },
    {
      data: 'total_cost',
      title: t.common.total,
      className: 'dt-num',
      priority: 3,
      filter: { type: 'text' },
      render: (row) => esc(money(Math.abs(row.total_cost))),
    },
    {
      data: 'source',
      title: t.inventory.source,
      orderable: false,
      className: 'dt-num dt-ltr muted text-xs',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => esc(row.source ?? '\u2014'),
    },
  ]

  return (
    <DataTable<StockMovement>
      url="/inventory/movements"
      exportName="stock-movements"
      columns={columns}
      order={['id', 'desc']}
      pageLength={30}
      onResponse={(payload) => {
        if (typeof payload.currency === 'string') setCurrency(payload.currency)
      }}
    />
  )
}
