import { useI18n } from '@/i18n'
import { formatDateTime } from '@/lib/date'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import type { AuditEntry } from '@/types'

/**
 * سجلّ التدقيق — للقراءة وحدها.
 *
 * ولأنّه يطول بطبعه، الفرز والتصفية فيه ليسا زينة: من يبحث عن فعلٍ
 * بعينه، أو عمّا فعله مستخدمٌ في يومٍ بعينه، لا ينبغي أن يقلّب الصفحات.
 */
export function AdminAudit() {
  const { t, locale } = useI18n()

  const tone = (action: string) =>
    action.includes('reject') || action.includes('suspend') || action.includes('deleted')
      ? 'danger'
      : action.includes('verif') || action.includes('settled')
        ? 'success'
        : 'accent'

  const columns: DataTableColumn<AuditEntry>[] = [
    {
      data: 'action',
      title: t.admin.action,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        `<span class="dt-chip dt-chip-${tone(row.action)} dt-ltr">${esc(row.action)}</span>` +
        (row.new_values
          ? `<pre class="muted text-[11px] mt-1.5 whitespace-pre-wrap break-all max-w-xs" dir="ltr">${esc(JSON.stringify(row.new_values))}</pre>`
          : ''),
    },
    {
      data: 'user',
      title: t.admin.performedBy,
      orderable: false,
      priority: 2,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.user?.name ?? '—')}</span>`,
    },
    {
      data: 'subject',
      title: t.admin.subject,
      orderable: false,
      className: 'dt-num dt-ltr muted text-sm',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(row.subject ? `${row.subject.type}#${row.subject.id}` : '—'),
    },
    {
      data: 'ip_address',
      title: 'IP',
      orderable: false,
      className: 'dt-num dt-ltr muted text-sm',
      priority: 5,
      filter: { type: 'text' },
      render: (row) => esc(row.ip_address ?? '—'),
    },
    {
      data: 'created_at',
      title: t.common.date,
      className: 'dt-num muted text-sm',
      priority: 3,
      filter: { type: 'dates' },
      render: (row) => esc(formatDateTime(row.created_at, locale)),
    },
  ]

  return (
    <DataTable<AuditEntry>
      url="/admin/audit-logs"
      exportName="audit-log"
      columns={columns}
      order={['id', 'desc']}
      pageLength={40}
    />
  )
}
