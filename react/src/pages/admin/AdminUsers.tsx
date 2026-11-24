import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { KeyRound, X } from 'lucide-react'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { useAuth } from '@/store/auth'
import { DataTable, type DataTableColumn } from '@/components/DataTable'
import { esc } from '@/lib/html'
import { Button, Card, Field, Input, Select } from '@/components/ui'
import type { AdminUserRow } from '@/types'
import { PhoneField } from '@/components/fields/PhoneField'

const STATUSES = ['active', 'inactive', 'suspended'] as const

/**
 * مستخدمو المنصة كلّهم.
 *
 * إيقاف حساب وإعادة ضبط كلمة مروره صلاحيتان خطرتان: كلتاهما تُنهي
 * جلسات صاحبها فوراً — فحسابٌ موقوف برمزٍ صالح ما زال يعمل، والإيقاف
 * حينها إعلانٌ لا منع. وكلتاهما تُسجَّل في سجلّ التدقيق باسم فاعلها.
 *
 * والمحرّر فوق الجدول لا داخله: DataTables يملك صفوفه ويبنيها نصوصاً،
 * فنموذجٌ فيه React لا يعيش بينها. فالنقر على القلم يرفع السطر إلى
 * لوحةٍ أعلى الجدول، وهي أوضح على الهاتف أيضاً من صفٍّ يتمدّد.
 */
export function AdminUsers() {
  const { t } = useI18n()
  const me = useAuth((s) => s.user)

  const [editing, setEditing] = useState<AdminUserRow | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [reloadKey, setReloadKey] = useState(0)

  function done() {
    setEditing(null)
    setError(null)
    setReloadKey((key) => key + 1)
  }

  const save = useMutation({
    mutationFn: async ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      (await api.patch(`/admin/users/${id}`, payload)).data,
    onSuccess: done,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const setPassword = useMutation({
    mutationFn: async ({ id, password }: { id: number; password: string }) =>
      (await api.post(`/admin/users/${id}/password`, { password, password_confirmation: password })).data,
    onSuccess: done,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  const tone = (status: string) =>
    status === 'active' ? 'success' : status === 'suspended' ? 'danger' : 'neutral'

  const columns: DataTableColumn<AdminUserRow>[] = [
    {
      data: 'name',
      title: t.account.name,
      priority: 1,
      filter: { type: 'text' },
      render: (row) =>
        '<div class="flex items-center gap-2.5">' +
        (row.avatar_url
          ? `<img src="${esc(row.avatar_url)}" alt="" class="h-8 w-8 rounded-full object-cover">`
          : `<span class="grid h-8 w-8 place-items-center rounded-full bg-[var(--surface)] text-xs font-bold text-accent">${esc(row.name.trim().charAt(0))}</span>`) +
        '<span class="min-w-0">' +
        `<span class="block font-medium" dir="auto">${esc(row.name)}</span>` +
        `<span class="muted block text-xs" dir="ltr">${esc(row.email)}</span>` +
        '</span></div>',
    },
    {
      data: 'tenant',
      title: t.admin.owner,
      orderable: false,
      priority: 3,
      filter: { type: 'text' },
      render: (row) => `<span dir="auto">${esc(row.tenant?.name ?? '—')}</span>`,
    },
    {
      data: 'type',
      title: t.common.filter,
      orderable: false,
      priority: 4,
      filter: {
        type: 'select',
        options: [
          { value: 'supplier', label: t.nav.suppliers },
          { value: 'buyer', label: t.admin.buyers },
          { value: 'platform', label: t.admin.title },
        ],
      },
      render: (row) => esc(row.tenant?.type ?? '—'),
    },
    {
      data: 'phone',
      title: t.account.phone,
      orderable: false,
      className: 'dt-ltr dt-num muted',
      priority: 4,
      filter: { type: 'text' },
      render: (row) => esc(row.phone ?? '—'),
    },
    {
      data: 'last_login_at',
      title: t.admin.lastLogin,
      orderable: false,
      className: 'dt-num muted text-xs',
      priority: 5,
      filter: { type: 'dates' },
      render: (row) => esc(row.last_login_at ? row.last_login_at.slice(0, 10) : t.admin.never),
    },
    {
      data: 'status',
      title: t.common.status,
      priority: 2,
      filter: {
        type: 'select',
        options: STATUSES.map((value) => ({ value, label: t.admin.userStatuses[value] })),
      },
      render: (row) =>
        `<span class="dt-chip dt-chip-${tone(row.status)}">${esc(
          t.admin.userStatuses[row.status as keyof typeof t.admin.userStatuses] ?? row.status,
        )}</span>`,
    },
    {
      data: null,
      title: t.common.actions,
      orderable: false,
      searchable: false,
      className: 'dt-actions',
      priority: 1,
      // حساب المدير نفسه لا يُدار من هنا: بابه صفحة الحساب
      render: (row) =>
        row.id === me?.id
          ? ''
          : `<button type="button" class="dt-btn" data-action="edit" data-id="${row.id}">${esc(t.common.edit)}</button>`,
    },
  ]

  return (
    <div className="space-y-4">
      {error && <div className="card p-4 text-sm text-danger">{error}</div>}

      {editing && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card>
            <div className="mb-3 flex items-center justify-between gap-3">
              <h2 className="font-semibold">
                <span dir="auto">{editing.name}</span>
              </h2>
              <Button variant="ghost" size="sm" aria-label={t.common.cancel} onClick={() => setEditing(null)}>
                <X size={15} />
              </Button>
            </div>

            <form
              className="grid gap-3 sm:grid-cols-2"
              onSubmit={(event) => {
                event.preventDefault()
                const form = new FormData(event.currentTarget)
                save.mutate({
                  id: editing.id,
                  payload: {
                    name: form.get('name'),
                    phone: form.get('phone') || null,
                    status: form.get('status'),
                  },
                })
              }}
            >
              <Field label={t.account.name}>
                <Input name="name" required defaultValue={editing.name} />
              </Field>
              <Field label={t.account.phone}>
                <PhoneField name="phone" defaultValue={editing.phone ?? ''} />
              </Field>
              <Field label={t.common.status}>
                <Select name="status" defaultValue={editing.status}>
                  {STATUSES.map((value) => (
                    <option key={value} value={value}>
                      {t.admin.userStatuses[value]}
                    </option>
                  ))}
                </Select>
              </Field>
              <div className="flex items-end">
                <Button type="submit" size="sm" disabled={save.isPending}>
                  {t.common.save}
                </Button>
              </div>
            </form>
          </Card>

          <Card className="h-fit">
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(event) => {
                event.preventDefault()
                const form = new FormData(event.currentTarget)
                setPassword.mutate({ id: editing.id, password: String(form.get('password')) })
              }}
            >
              <div className="min-w-48 flex-1">
                <Field label={t.admin.resetPassword}>
                  <Input name="password" type="password" minLength={8} required autoComplete="new-password" />
                </Field>
              </div>
              <Button type="submit" size="sm" variant="outline" disabled={setPassword.isPending}>
                <KeyRound size={14} />
                {t.admin.setPassword}
              </Button>
            </form>
          </Card>
        </div>
      )}

      <DataTable<AdminUserRow>
        url="/admin/users"
        exportName="users"
        columns={columns}
        order={['id', 'desc']}
        reloadKey={reloadKey}
        onAction={(action, _id, _value, row) => {
          if (action === 'edit' && row) setEditing((current) => (current?.id === row.id ? null : row))
        }}
      />
    </div>
  )
}
