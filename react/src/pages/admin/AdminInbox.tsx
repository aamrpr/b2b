import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Archive, MailOpen } from 'lucide-react'
import { api } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatDateTime } from '@/lib/date'
import { Badge, Button, Card, Empty, ErrorState, Loading, Pager, Select } from '@/components/ui'
import type { AdminListMeta } from '@/types'

interface InboxMessage {
  id: number
  name: string
  email: string
  phone: string | null
  subject: string
  message: string
  status: string
  sender: string | null
  created_at: string | null
}

/**
 * صندوق رسائل التواصل.
 *
 * ما يصل من صفحة «عن المنصة»: سؤال زائرٍ لم يسجّل بعد، أو شكوى مستوردٍ
 * من المنصة نفسها. وهي ليست محادثات تجارية بين مستأجرين، فلها صندوقها
 * هنا لا جدول المحادثات — ويبقى الردّ على بريد المرسِل.
 */
export function AdminInbox() {
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['admin-inbox', status, page],
    queryFn: async () =>
      (
        await api.get<{ data: InboxMessage[]; meta: AdminListMeta }>('/admin/contact-messages', {
          params: { status: status || undefined, page },
        })
      ).data,
  })

  const setMessageStatus = useMutation({
    mutationFn: async ({ id, next }: { id: number; next: string }) =>
      (await api.patch(`/admin/contact-messages/${id}`, { status: next })).data,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-inbox'] }),
  })

  const tone = (value: string) => (value === 'new' ? 'brand' : value === 'archived' ? 'neutral' : 'success')

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Select
          aria-label={t.common.status}
          className="max-w-44"
          value={status}
          onChange={(event) => {
            setStatus(event.target.value)
            setPage(1)
          }}
        >
          <option value="">{t.common.all}</option>
          {(['new', 'read', 'archived'] as const).map((value) => (
            <option key={value} value={value}>
              {t.admin.inboxStatuses[value]}
            </option>
          ))}
        </Select>

        <span className="muted ms-auto text-sm tabular">{data?.meta.total ?? 0}</span>
      </div>

      {isLoading ? (
        <Loading message={t.common.loading} />
      ) : isError ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : !data?.data.length ? (
        <Empty message={t.common.empty} />
      ) : (
        <div className="grid gap-4">
          {data.data.map((message) => (
            <Card key={message.id}>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-semibold">
                      <span dir="auto">{message.subject}</span>
                    </h2>
                    <Badge tone={tone(message.status)}>
                      {t.admin.inboxStatuses[message.status as keyof typeof t.admin.inboxStatuses] ??
                        message.status}
                    </Badge>
                  </div>

                  <div className="muted mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    <span dir="auto">{message.name}</span>
                    <a href={`mailto:${message.email}`} className="hover:text-accent" dir="ltr">
                      {message.email}
                    </a>
                    {message.phone && <span dir="ltr">{message.phone}</span>}
                    {message.sender && <span dir="auto">· {message.sender}</span>}
                    <span className="tabular">{formatDateTime(message.created_at, locale)}</span>
                  </div>
                </div>

                <div className="flex shrink-0 gap-1">
                  {message.status !== 'read' && (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={t.admin.markRead}
                      onClick={() => setMessageStatus.mutate({ id: message.id, next: 'read' })}
                    >
                      <MailOpen size={15} />
                    </Button>
                  )}
                  {message.status !== 'archived' && (
                    <Button
                      variant="ghost"
                      size="sm"
                      aria-label={t.admin.archive}
                      onClick={() => setMessageStatus.mutate({ id: message.id, next: 'archived' })}
                    >
                      <Archive size={15} />
                    </Button>
                  )}
                </div>
              </div>

              <p dir="auto" className="mt-3 whitespace-pre-line text-sm leading-relaxed">
                {message.message}
              </p>
            </Card>
          ))}
        </div>
      )}

      <Pager meta={data?.meta} page={page} onChange={setPage} />
    </div>
  )
}
