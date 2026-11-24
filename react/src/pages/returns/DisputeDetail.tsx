import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { AlertTriangle, Gavel, Paperclip, Send, Shield, UserCheck } from 'lucide-react'
import clsx from 'clsx'
import { api, errorMessage } from '@/lib/api'
import { useI18n } from '@/i18n'
import { formatAmount, toMinor } from '@/lib/money'
import { useAuth, isPlatform } from '@/store/auth'
import { Badge, Button, Card, ErrorState, Field, Input, Loading, PageTitle, Select, Stat, Textarea } from '@/components/ui'
import type { DisputeDetail as Detail } from '@/types'

export function DisputeDetail() {
  const { disputeId = '' } = useParams()
  const { t, locale } = useI18n()
  const queryClient = useQueryClient()
  const user = useAuth((s) => s.user)
  const mediator = isPlatform(user)

  const [draft, setDraft] = useState('')
  const [internal, setInternal] = useState(false)
  const [panel, setPanel] = useState<'resolve' | 'request' | null>(null)
  const [error, setError] = useState<string | null>(null)

  const { data, isLoading, isError, error: queryError, refetch } = useQuery({
    queryKey: ['dispute', disputeId],
    queryFn: async () => (await api.get<{ dispute: Detail }>(`/disputes/${disputeId}`)).data.dispute,
  })

  function refresh() {
    void queryClient.invalidateQueries({ queryKey: ['dispute', disputeId] })
    void queryClient.invalidateQueries({ queryKey: ['disputes'] })
    setDraft('')
    setPanel(null)
    setError(null)
  }

  const send = useMutation({
    mutationFn: async (form: FormData) => (await api.post(`/disputes/${disputeId}/messages`, form)).data,
    onSuccess: refresh,
    onError: (err) => setError(errorMessage(err, t.common.error)),
  })

  /** إجراءات الوسيط تتشابه في الشكل وتختلف في المسار فقط. */
  function mediatorAction(path: string) {
    return {
      mutationFn: async (payload: Record<string, unknown>) =>
        (await api.post(`/disputes/${disputeId}/${path}`, payload)).data,
      onSuccess: refresh,
      onError: (err: unknown) => setError(errorMessage(err, t.common.error)),
    }
  }

  const assign = useMutation(mediatorAction('assign'))
  const requestResponse = useMutation(mediatorAction('request-response'))
  const resolve = useMutation(mediatorAction('resolve'))
  const close = useMutation(mediatorAction('close'))

  if (isError) return <ErrorState error={queryError} onRetry={() => void refetch()} />

  if (isLoading || !data) return <Loading message={t.common.loading} />

  const statuses = t.disputes.statuses as Record<string, string>
  const reasons = t.disputes.reasons as Record<string, string>
  const outcomes = t.disputes.outcomes as Record<string, string>
  const eventTypes = t.disputes.eventTypes as Record<string, string>
  const money = (value: number) => formatAmount(value, data.currency, 2, locale)

  const isOpen = ! ['resolved', 'closed'].includes(data.status)
  const canResolve = mediator && data.allowed_transitions.includes('resolved')
  const canRequest = mediator && data.allowed_transitions.includes('awaiting_party')

  return (
    <div className="space-y-5">
      <PageTitle title={t.disputes.title} back="/returns/disputes" />
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2 flex-wrap">
              <h2 className="text-lg font-bold tabular">{data.reference_no}</h2>
              <Badge tone={data.status === 'resolved' ? 'success' : data.status === 'closed' ? 'neutral' : 'brand'}>
                {statuses[data.status] ?? data.status}
              </Badge>
              {data.is_overdue && (
                <Badge tone="danger">
                  <AlertTriangle size={11} className="me-1" />
                  {t.disputes.overdue}
                </Badge>
              )}
            </div>

            <div className="muted text-sm mt-1.5 tabular">
              {data.order.order_no} · {reasons[data.reason] ?? data.reason}
              {data.return_request && ` · ${data.return_request.reference_no}`}
            </div>

            <p className="text-sm mt-3 whitespace-pre-wrap">{data.description}</p>

            <div className="muted text-xs mt-3">
              {data.buyer} ⟷ {data.supplier}
              {data.mediator && ` · ${t.disputes.mediator}: ${data.mediator}`}
            </div>
          </div>

          {mediator && isOpen && (
            <div className="flex flex-col gap-2">
              {! data.mediator && (
                <Button size="sm" disabled={assign.isPending} onClick={() => assign.mutate({})}>
                  <UserCheck size={15} />
                  {t.disputes.assignToMe}
                </Button>
              )}

              {canRequest && (
                <Button size="sm" variant="outline" onClick={() => setPanel(panel === 'request' ? null : 'request')}>
                  {t.disputes.requestResponse}
                </Button>
              )}

              {canResolve && (
                <Button size="sm" onClick={() => setPanel(panel === 'resolve' ? null : 'resolve')}>
                  <Gavel size={15} />
                  {t.disputes.resolveAction}
                </Button>
              )}

              <Button size="sm" variant="ghost" onClick={() => close.mutate({})}>
                {t.disputes.closeAction}
              </Button>
            </div>
          )}
        </div>

        {panel === 'request' && (
          <form
            className="mt-4 pt-4 border-t border-[var(--border)] flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              requestResponse.mutate({ message: form.get('message') })
            }}
          >
            <div className="flex-1 min-w-48">
              <Field label={t.disputes.requestResponse}>
                <Input name="message" required />
              </Field>
            </div>
            <Button type="submit" size="sm">
              {t.common.send}
            </Button>
          </form>
        )}

        {panel === 'resolve' && (
          <form
            className="mt-4 pt-4 border-t border-[var(--border)] grid gap-4 sm:grid-cols-2"
            onSubmit={(event) => {
              event.preventDefault()
              const form = new FormData(event.currentTarget)
              resolve.mutate({
                outcome: form.get('outcome'),
                resolution_amount: toMinor(String(form.get('resolution_amount') ?? '0')),
                resolution_note: form.get('resolution_note'),
              })
            }}
          >
            <Field label={t.disputes.outcome}>
              <Select name="outcome" defaultValue="partial">
                {Object.entries(outcomes).map(([key, label]) => (
                  <option key={key} value={key}>
                    {label}
                  </option>
                ))}
              </Select>
            </Field>

            <Field label={t.disputes.resolutionAmount}>
              <Input name="resolution_amount" type="number" step="0.01" min="0" className="tabular" />
            </Field>

            <div className="sm:col-span-2">
              <Field label={t.disputes.resolutionNote}>
                <Textarea name="resolution_note" required />
              </Field>
            </div>

            <div className="sm:col-span-2">
              <Button type="submit" size="sm" disabled={resolve.isPending}>
                <Gavel size={15} />
                {t.disputes.resolveAction}
              </Button>
            </div>
          </form>
        )}

        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      </Card>

      <div className="grid gap-3 sm:grid-cols-3">
        <Stat label={t.disputes.claimedAmount} value={money(data.claimed_amount)} />
        <Stat
          label={t.disputes.resolutionAmount}
          value={money(data.resolution_amount)}
          tone={data.resolution_amount > 0 ? 'success' : undefined}
        />
        <Stat label={t.disputes.dueAt} value={data.due_at?.slice(0, 10) ?? '—'} />
      </div>

      {data.outcome && (
        <Card>
          <div className="flex items-center gap-2">
            <Gavel size={16} className="text-success" />
            <span className="font-semibold">{outcomes[data.outcome] ?? data.outcome}</span>
          </div>
          {data.resolution_note && <p className="muted text-sm mt-2 whitespace-pre-wrap">{data.resolution_note}</p>}
        </Card>
      )}

      <Card>
        <h3 className="font-semibold mb-4">{t.disputes.thread}</h3>

        <ol className="space-y-4">
          {data.events.map((event) => (
            <li
              key={event.id}
              className={clsx(
                'rounded-xl border p-3',
                event.is_internal
                  ? 'border-[var(--warning)]/40 bg-[var(--warning-soft)]'
                  : event.from_platform
                    ? 'border-brand-500/40 bg-[var(--accent-soft)]'
                    : 'border-[var(--border)]',
              )}
            >
              <div className="flex flex-wrap items-center gap-2 text-xs">
                <span className="font-medium">
                  {event.from_platform ? t.disputes.platform : event.company}
                </span>
                {event.author && <span className="muted">{event.author}</span>}
                <Badge tone={event.type === 'decision' ? 'success' : 'neutral'}>
                  {eventTypes[event.type] ?? event.type}
                </Badge>
                {event.is_internal && (
                  <Badge tone="warning">
                    <Shield size={10} className="me-1" />
                    {t.disputes.internalNote}
                  </Badge>
                )}
                <span className="muted tabular ms-auto">{event.created_at?.slice(0, 16).replace('T', ' ')}</span>
              </div>

              {event.body && <p className="text-sm mt-2 whitespace-pre-wrap">{event.body}</p>}

              {event.file_url && (
                <a
                  href={event.file_url}
                  target="_blank"
                  rel="noreferrer"
                  className="mt-2 inline-flex items-center gap-1.5 text-xs text-accent hover:underline"
                >
                  <Paperclip size={12} />
                  {t.messages.attachment}
                </a>
              )}
            </li>
          ))}
        </ol>

        {isOpen ? (
          <form
            className="mt-5 pt-5 border-t border-[var(--border)] space-y-3"
            onSubmit={(event) => {
              event.preventDefault()
              const element = event.currentTarget
              const file = (element.elements.namedItem('evidence') as HTMLInputElement)?.files?.[0]

              const form = new FormData()
              form.append('body', draft)
              if (file) form.append('file', file)
              if (internal) form.append('is_internal', '1')

              send.mutate(form)
              element.reset()
            }}
          >
            <Textarea
              value={draft}
              onChange={(e) => setDraft(e.target.value)}
              placeholder={t.disputes.writeMessage}
              required
            />

            <div className="flex flex-wrap items-center gap-4">
              <label className="cursor-pointer text-sm text-accent inline-flex items-center gap-1.5">
                <input type="file" name="evidence" className="hidden" />
                <Paperclip size={15} />
                {t.disputes.attachEvidence}
              </label>

              {mediator && (
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} />
                  {t.disputes.internalNote}
                </label>
              )}

              <Button type="submit" size="sm" className="ms-auto" disabled={send.isPending || ! draft.trim()}>
                <Send size={15} />
                {t.common.send}
              </Button>
            </div>
          </form>
        ) : (
          <p className="muted text-sm mt-5 pt-5 border-t border-[var(--border)]">{t.disputes.closedNoMessages}</p>
        )}
      </Card>
    </div>
  )
}
